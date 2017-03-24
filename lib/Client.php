<?php
/**
 * Implementation of the `coveralls\Client` class.
 */
namespace coveralls;

use Evenement\{EventEmitterTrait};
use GuzzleHttp\{Client as HTTPClient};
use GuzzleHttp\Psr7\{MultipartStream, ServerRequest};
use lcov\{Record, Report, Token};
use Webmozart\PathUtil\{Path};

/**
 * Uploads code coverage reports to the [Coveralls](https://coveralls.io) service.
 */
class Client {
  use EventEmitterTrait;

  /**
   * @var string The URL of the default API end point.
   */
  const DEFAULT_ENDPOINT = 'https://coveralls.io';

  /**
   * @var string The URL of the API end point.
   */
  private $endPoint;

  /**
   * Initializes a new instance of the class.
   * @param string $endPoint The URL of the API end point.
   */
  public function __construct(string $endPoint = self::DEFAULT_ENDPOINT) {
    $this->setEndPoint($endPoint);
  }

  /**
   * Gets the URL of the API end point.
   * @return string The URL of the API end point.
   */
  public function getEndPoint(): string {
    return $this->endPoint;
  }

  /**
   * Sets the URL of the API end point.
   * @param string $value The new URL of the API end point.
   * @return Client This instance.
   */
  public function setEndPoint(string $value) {
    $this->endPoint = $value;
    return $this;
  }

  /**
   * Uploads the specified code coverage report to the Coveralls service.
   * @param string $coverage A coverage report.
   * @param Configuration $config The environment settings.
   * @throws \InvalidArgumentException The specified coverage report is empty or its format is not supported.
   */
  public function upload(string $coverage, Configuration $config = null) {
    $coverage = trim($coverage);
    if (!mb_strlen($coverage)) throw new \InvalidArgumentException('The specified coverage report is empty.');

    $job = null;
    $isClover = mb_substr($coverage, 0, 5) == '<?xml' || mb_substr($coverage, 0, 10) == '<coverage';
    if ($isClover) $job = $this->parseCloverReport($coverage);
    else {
      $token = mb_substr($coverage, 0, 3);
      if ($token == Token::TEST_NAME.':' || $token == Token::SOURCE_FILE.':') $job = $this->parseLcovReport($coverage);
    }

    if (!$job) throw new \InvalidArgumentException('The specified coverage format is not supported.');
    $this->updateJob($job, $config ?: Configuration::loadDefaults());
    if (!$job->getRunAt()) $job->setRunAt(time());

    $command = PHP_OS == 'WINNT' ? 'where.exe git.exe' : 'which git';
    if (mb_strlen(trim(`$command`))) {
      $branch = ($git = $job->getGit()) ? $git->getBranch() : '';
      $job->setGit(GitData::fromRepository());

      $git = $job->getGit();
      if ($git->getBranch() == 'HEAD' && mb_strlen($branch)) $git->setBranch($branch);
    }

    $this->uploadJob($job);
  }

  /**
   * Uploads the specified job to the Coveralls service.
   * @param Job $job The job to be uploaded.
   * @emits \GuzzleHttp\Psr7\ServerRequest The "request" event.
   * @emits \GuzzleHttp\Psr7\Response The "response" event.
   * @throws \InvalidArgumentException The job does not meet the requirements.
   * @throws \RuntimeException An error occurred while uploading the report.
   */
  public function uploadJob(Job $job) {
    if (!$job->getRepoToken() && !$job->getServiceName())
      throw new \InvalidArgumentException('The job does not meet the requirements.');

    $jsonFile = [
      'contents' => json_encode($job, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
      'filename' => 'coveralls.json',
      'name' => 'json_file'
    ];

    try {
      $body = new MultipartStream([$jsonFile]);
      $request = (new ServerRequest('POST', $this->getEndPoint().'/api/v1/jobs'))->withBody($body);
      $this->emit('request', [$request]);

      $response = (new HTTPClient())->send($request, ['multipart' => [$jsonFile]]);
      $this->emit('reponse', [$response]);

      if (($code = $response->getStatusCode()) != 200)
        throw new \DomainException("$code {$response->getReasonPhrase()}");
    }

    catch (\Throwable $e) {
      throw new \RuntimeException('An error occurred while uploading the report.');
    }
  }

  /**
   * Parses the specified [Clover](https://www.atlassian.com/software/clover) coverage report.
   * @param string $report A coverage report in LCOV format.
   * @return Job The job corresponding to the specified coverage report.
   * @throws \InvalidArgumentException The specified Clover report has an invalid format.
   * @throws \RuntimeException A source file was not found.
   */
  private function parseCloverReport(string $report): Job {
    $xml = simplexml_load_string($report);
    if (!$xml || !$xml->count() || !$xml->project->count())
      throw new \InvalidArgumentException('The specified Clover report is invalid.'.$report);

    $sourceFiles = [];
    $workingDir = getcwd();

    foreach (['/coverage/project/file', '/coverage/project/package/file'] as $xpath) {
      foreach ($xml->xpath($xpath) as $file) {
        $path = (string) $file['name'];
        $source = @file_get_contents($path);
        if (!$source) throw new \RuntimeException("Source file not found: $path");

        $lines = preg_split('/\r?\n/', $source);
        $coverage = new \SplFixedArray(count($lines));
        foreach ($file->line as $line) {
          if ((string) $line['type'] == 'stmt') $coverage[(int) $line['num'] - 1] = (int) $line['count'];
        }

        $filename = Path::makeRelative($path, $workingDir);
        $sourceFiles[] = new SourceFile($filename, md5($source), $source, $coverage->toArray());
      }
    }

    return (new Job($sourceFiles))->setRunAt((int) $xml->project['timestamp']);
  }

  /**
   * Parses the specified [LCOV](http://ltp.sourceforge.net/coverage/lcov.php) coverage report.
   * @param string $report A coverage report in LCOV format.
   * @return Job The job corresponding to the specified coverage report.
   * @throws \RuntimeException A source file was not found.
   */
  private function parseLcovReport(string $report): Job {
    $records = Report::parse($report)->getRecords()->getArrayCopy();
    $workingDir = getcwd();

    return new Job(array_map(function(Record $record) use ($workingDir) {
      $path = $record->getSourceFile();
      $source = @file_get_contents($path);
      if (!$source) throw new \RuntimeException("Source file not found: $path");

      $lines = preg_split('/\r?\n/', $source);
      $coverage = new \SplFixedArray(count($lines));
      foreach ($record->getLines()->getData() as $lineData) $coverage[$lineData->getLineNumber() - 1] = $lineData->getExecutionCount();

      $filename = Path::makeRelative($path, $workingDir);
      return new SourceFile($filename, md5($source), $source, $coverage->toArray());
    }, $records));
  }

  /**
   * Updates the properties of the specified job using the given configuration parameters.
   * @param Job $job The job to update.
   * @param Configuration $config The parameters to define.
   */
  private function updateJob(Job $job, Configuration $config) {
    if (mb_strlen($config['repo_token']) || mb_strlen($config['repo_secret_token']))
      $job->setRepoToken($config['repo_token'] ?: $config['repo_secret_token']);

    if (mb_strlen($config['parallel'])) $job->setParallel($config['parallel'] == 'true');
    if (mb_strlen($config['run_at'])) $job->setRunAt($config['run_at']);
    if (mb_strlen($config['service_job_id'])) $job->setServiceJobId($config['service_job_id']);
    if (mb_strlen($config['service_name'])) $job->setServiceName($config['service_name']);
    if (mb_strlen($config['service_number'])) $job->setServiceNumber($config['service_number']);
    if (mb_strlen($config['service_pull_request'])) $job->setServicePullRequest($config['service_pull_request']);

    $hasGitData = count(array_filter($config->getKeys(), function($key) {
      return $key == 'service_branch' || mb_substr($key, 0, 4) == 'git_';
    })) > 0;

    if (!$hasGitData) $job->setCommitSha($config['commit_sha'] ?: '');
    else {
      $commit = new GitCommit($config['commit_sha'] ?: '', $config['git_message'] ?: '');
      $commit->setAuthorEmail($config['git_author_email'] ?: '');
      $commit->setAuthorName($config['git_author_name'] ?: '');
      $commit->setCommitterEmail($config['git_committer_email'] ?: '');
      $commit->setCommitterName($config['git_committer_email'] ?: '');

      $job->setGit(new GitData($commit, $config['service_branch'] ?: ''));
    }
  }
}
