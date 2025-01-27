<?php

namespace Midweste\GitPluginRepository\Plugins;

use Midweste\GitPluginRepository\Helpers;
use Midweste\GitPluginRepository\UpdaterBase;

/*
* Bitbucket Plugin Updater
* Specify Update URI: in your plugin comment block
*
* Use a branch for updates:
* Update URI: https://api.bitbucket.org/2.0/repositories/{$repoOwner}/{$repoName}/commits/{$repoBranch}
* Use tags for updates:
* Update URI: https://api.bitbucket.org/2.0/repositories/{$repoOwner}/{$repoName}/ref/tags
*/

class Bitbucket extends UpdaterBase //phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
{
    protected static string $updateHost = 'api.bitbucket.org';
    protected string $apiBase = 'https://api.bitbucket.org/2.0/repositories';
    protected string $repoOwner;
    protected string $repoName;
    protected string $repoType;
    protected string $repoBranch;

    public function __construct(string $plugin_file)
    {
        parent::__construct($plugin_file);

        list('owner' => $repoOwner, 'repo' => $repoName, 'type' =>  $repoType, 'branch' => $repoBranch) = $this->parseUri($this->getPluginData('UpdateURI'));

        $repoOwner = trim(strtolower($repoOwner));
        if (empty($repoOwner)) {
            throw new \Exception(sprintf('Could not determine repoOwner.'));
        }
        $repoName = trim(strtolower($repoName));
        if (empty($repoName)) {
            throw new \Exception(sprintf('Could not determine repoName.'));
        }
        $repoType = trim(strtolower($repoType));
        if (!in_array($repoType, ['commits', 'refs'], true)) {
            throw new \Exception(sprintf('Url is not in the correct format. Type %s was invalid.'));
        }

        $this->repoOwner = $repoOwner;
        $this->repoName = $repoName;
        $this->repoType = $repoType;
        $this->repoBranch = $repoBranch;
    }

    public static function getUpdateHost(): string
    {
        return self::$updateHost;
    }

    public function getLatestBranchCommit(): string
    {
        $url = $this->apiBase . "/{$this->repoOwner}/{$this->repoName}/commits/{$this->repoBranch}";
        $response = $this->requestBody($url);
        if ($response === false || empty($response['values'][0]['date'])) {
            return '';
        }
        return Helpers::date_to_version($response['values'][0]['date']);
    }

    public function getLatestBranchCommitZip(): string
    {
        $url = $this->apiBase . "/{$this->repoOwner}/{$this->repoName}/commits/{$this->repoBranch}";
        $response = $this->requestBody($url);
        if ($response === false || empty($response['values'][0]['hash'])) {
            return '';
        }
        $hash = substr($response['values'][0]['hash'], 0, 12);
        $zip = "https://bitbucket.org/{$this->repoOwner}/{$this->repoName}/get/{$hash}.zip";
        return $zip;
    }

    public function getLatestTag(): string
    {
        $url = $this->apiBase . "/{$this->repoOwner}/{$this->repoName}/refs/tags";
        $response = $this->requestBody($url);
        if ($response === false || empty($response['values'][0]['name'])) {
            return '';
        }
        return $response['values'][0]['name'];
    }

    public function getLatestTagZip(): string
    {
        $url = $this->apiBase . "/{$this->repoOwner}/{$this->repoName}/refs/tags";
        $response = $this->requestBody($url);
        if ($response === false || empty($response['values'][0]['target']['hash'])) {
            return '';
        }
        $hash = $response['values'][0]['target']['hash'];
        $zip = "https://bitbucket.org/{$this->repoOwner}/{$this->repoName}/get/{$hash}.zip";
        return $zip;
    }

    public function getLatestVersion(): string
    {
        $version = '';
        if ($this->repoType === 'refs') {
            $version = $this->getLatestTag();
        } elseif ($this->repoType === 'commits') {
            $version = $this->getLatestBranchCommit();
        }
        return $version;
    }

    public function getLatestVersionZip(): string
    {
        $zip = '';
        if ($this->repoType === 'refs') {
            $zip = $this->getLatestTagZip();
        } elseif ($this->repoType === 'commits') {
            $zip = $this->getLatestBranchCommitZip();
        }
        return $zip;
    }

    protected function parseUri(string $uri): array
    {
        //Update URI: https://api.bitbucket.org/2.0/repositories/owner/repo/ref/tags
        //Update URI: https://api.bitbucket.org/2.0/repositories/owner/repo/commits/branch
        $uri = trim(strtolower($uri));
        preg_match('/https:\/\/api.bitbucket.org\/2\.0\/repositories\/(?<owner>[^\/]+)\/(?<repo>[^\/]+)\/(?<type>[^\/]+)\/?(?<branch>[^\/]+)?$/', $uri, $matches);
        return [
            'owner' => (isset($matches['owner'])) ? $matches['owner'] : '',
            'repo' => (isset($matches['repo'])) ? $matches['repo'] : '',
            'type' => (isset($matches['type'])) ? $matches['type'] : '',
            'branch' => (isset($matches['branch'])) ? $matches['branch'] : '',
        ];
    }
}
