<?php

namespace Midweste\GitPluginRepository\Plugins;

use Midweste\GitPluginRepository\Helpers;
use Midweste\GitPluginRepository\UpdaterBase;

/*
* GithubUpdater Plugin Updater
* Specify Update URI: in your plugin comment block
*
* Use releases for updates:
* Update URI: https://api.github.com/repos/{$repoOwner}/{$repoName}/releases
* Use a branch for updates:
* Update URI: https://api.github.com/repos/{$repoOwner}/{$repoName}/commits/{$repoBranch}
* Use tags for updates:
* Update URI: https://api.github.com/repos/{$repoOwner}/{$repoName}/ref/tags
*/

class Github extends UpdaterBase //phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
{
    protected static string $updateHost = 'api.github.com';
    protected string $apiBase = 'https://api.github.com/repos';
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
        if (!in_array($repoType, ['releases', 'commits', 'refs'], true)) {
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
        if ($response === false || !isset($response['commit']['committer']['date'])) {
            return '';
        }
        $version = Helpers::date_to_version($response['commit']['committer']['date']);
        return $version;
    }

    public function getLatestBranchCommitZip(): string
    {
        $url = $this->apiBase . "/{$this->repoOwner}/{$this->repoName}/zipball/{$this->repoBranch}";
        return $url;
    }

    public function getLatestTag(): string
    {
        $url = $this->apiBase . "/{$this->repoOwner}/{$this->repoName}/tags";
        $response = $this->requestBody($url);
        if ($response === false || empty($response[0]['name'])) {
            return '';
        }
        return $response[0]['name'];
    }

    public function getLatestTagZip(): string
    {
        $url = $this->apiBase . "/{$this->repoOwner}/{$this->repoName}/tags";
        $response = $this->requestBody($url);
        if ($response === false || empty($response[0]['zipball_url'])) {
            return '';
        }
        return $response[0]['zipball_url'];
    }

    public function getLatestRelease(): string
    {
        $url = $this->apiBase . "/{$this->repoOwner}/{$this->repoName}/releases/latest";
        $response = $this->requestBody($url);
        if ($response === false || empty($response['name'])) {
            return '';
        }
        return $response['name'];
    }

    public function getLatestReleaseZip(): string
    {
        $url = $this->apiBase . "/{$this->repoOwner}/{$this->repoName}/releases/latest";
        $response = $this->requestBody($url);
        if ($response === false || empty($response['zipball_url'])) {
            return '';
        }
        return $response['zipball_url'];
    }

    public function getLatestVersion(): string
    {
        $version = '';
        if ($this->repoType === 'releases') {
            $version = $this->getLatestRelease();
        } elseif ($this->repoType === 'tags') {
            $version = $this->getLatestTag();
        } elseif ($this->repoType === 'commits') {
            $version = $this->getLatestBranchCommit();
        }
        return ltrim($version, 'v');
    }

    public function getLatestVersionZip(): string
    {
        $zip = '';
        if ($this->repoType === 'releases') {
            $zip = $this->getLatestReleaseZip();
        } elseif ($this->repoType === 'tags') {
            $zip = $this->getLatestTagZip();
        } elseif ($this->repoType === 'commits') {
            $zip = $this->getLatestBranchCommitZip();
        }
        return $zip;
    }

    protected static function parseUri(string $url): array
    {
        //Update URI: https://api.github.com/repos/owner/repo/tags
        $url = trim(strtolower($url));
        preg_match('/https:\/\/api.github.com\/repos\/(?<owner>[^\/]+)\/(?<repo>[^\/]+)\/(?<type>[^\/]+)\/?(?<branch>[^\/]+)?$/', $url, $matches);
        return [
            'owner' => (isset($matches['owner'])) ? $matches['owner'] : '',
            'repo' => (isset($matches['repo'])) ? $matches['repo'] : '',
            'type' => (isset($matches['type'])) ? $matches['type'] : '',
            'branch' => (isset($matches['branch'])) ? $matches['branch'] : '',
        ];
    }
}
