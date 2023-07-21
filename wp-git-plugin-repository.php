<?php

namespace GitPluginRepository;

/*
 * Plugin Name:       WordPress Github/Bitbucket Plugin Updater
 * Plugin URI:        https://github.org/midweste/wp-git-plugin-repository
 * Description:       Test Use a Github/Bitbucket public repo api as a plugin repository for the Wordpress built in plugin updater function new
 * Author:            Midweste
 * Author URI:        https://github.org/midweste/wp-git-plugin-repository
 * Update URI:        https://raw.githubusercontent.com/midweste/wp-git-plugin-repository/main/wp-git-plugin-repository.php
 * License:           MIT
 */

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

/*
* Bitbucket Plugin Updater
* Specify Update URI: in your plugin comment block
*
* Use a branch for updates:
* Update URI: https://api.bitbucket.org/2.0/repositories/{$repoOwner}/{$repoName}/commits/{$repoBranch}
* Use tags for updates:
* Update URI: https://api.bitbucket.org/2.0/repositories/{$repoOwner}/{$repoName}/ref/tags
*/

abstract class UpdaterBase
{
    protected array $plugin_data;
    protected string $plugin_file;
    protected $request_cache = [];

    abstract public static function getUpdateHost(): string;
    abstract public function getLatestVersion(): string;
    abstract public function getLatestVersionZip(): string;

    public function __construct(string $plugin_file)
    {
        if (empty($plugin_file)) {
            throw new \Exception(sprintf('Missing plugin file.'));
        }

        $file_path = WP_PLUGIN_DIR . \DIRECTORY_SEPARATOR . $plugin_file;
        if (!file_exists($file_path)) {
            throw new \Exception(sprintf('Plugin file %s does not exist.', $file_path));
        }

        $plugin_data = get_plugin_data($file_path, false, false);
        if (empty(trim($plugin_data['UpdateURI'])) || !filter_var($plugin_data['UpdateURI'], FILTER_VALIDATE_URL)) {
            throw new \Exception(sprintf('Missing or invalid Update URI.'));
        }

        $this->plugin_data = $plugin_data;
        $this->plugin_file = $plugin_file;
    }

    protected function getPluginData(string $key = '')
    {
        if (empty($key)) {
            return $this->plugin_data;
        }
        return (isset($this->plugin_data[$key])) ? $this->plugin_data[$key] : [];
    }

    protected function getPluginFile(): string
    {
        return $this->plugin_file;
    }

    protected function getRequestCache(): array
    {
        return $this->request_cache;
    }

    protected function getSlug(): string
    {
        return current(explode('/', $this->getPluginFile()));
    }

    public function update($locales = []): ?object
    {
        $version = !empty($this->getPluginData('Version')) ? $this->getPluginData('Version') : 0;
        $new_version = ltrim($this->getLatestVersion(), 'v');
        if (empty($new_version) || !version_compare($new_version, $version, '>')) {
            return null;
        }

        $package_url = $this->getLatestVersionZip();
        if (!filter_var($package_url, FILTER_VALIDATE_URL) || !$this->zipVerify($package_url)) {
            return null;
        }

        $package_local = Helpers::zip_proxy($package_url, $this->getPluginFile(), $new_version);
        if (empty($package_local)) {
            return null;
        }
        $update = (object) [
            'slug' => $this->getSlug(),  // needed for /wp-admin/update-core.php
            'version' => $version,
            'new_version' => $new_version,
            'package' => $package_local,
        ];
        return $update;
    }

    protected function request(string $url)
    {
        if (isset($this->getRequestCache()[$url])) {
            return $this->getRequestCache()[$url];
        }
        $response = wp_remote_get($url);
        $this->request_cache[$url] = $response;
        return $response;
    }

    protected function requestBody(string $url)
    {
        $request = $this->request($url);
        $data = json_decode(wp_remote_retrieve_body($request), true);
        return (is_array($data)) ? $data : false;
    }

    protected function requestHeaders(string $url): array
    {
        $stream = [
            'http' => [
                'user_agent' => 'Mozilla/5.0'
            ]
        ];
        $response = get_headers($url, true, stream_context_create($stream));
        return $response;
    }

    protected function zipVerify(string $url): bool
    {
        $response = $this->requestHeaders($url);
        if (!isset($response['Content-Type'])) {
            return false;
        }
        if (is_array($response['Content-Type']) && strpos(end($response['Content-Type']), 'application/zip') === 0) {
            return true;
        }
        if (!is_array($response['Content-Type']) && strpos($response['Content-Type'], 'application/zip') === 0) {
            return true;
        }
        return false;
    }

    public static function getImplementations(): array
    {
        $implementations = [];
        $classes = get_declared_classes();
        foreach ($classes as $class) {
            if (is_subclass_of($class, __CLASS__)) {
                $implementations[] = '\\' . $class;
            }
        }
        return $implementations;
    }
}

class Helpers //phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
{
    public static function date_to_version(string $date): string
    {
        return str_replace(['-', 'T', ':', '+'], '.', rtrim($date, 'Z'));
    }

    protected static function rmdir(string $dir): bool
    {
        if (!is_dir($dir) || strpos($dir, self::cache_dir()) !== 0) {
            return false;
        }
        $it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        return rmdir($dir);
    }

    public static function cache_dir(): string
    {
        $upload_dir = wp_upload_dir();
        $cache_path = $upload_dir['basedir'] . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'plugins';
        return $cache_path;
    }

    public static function cache_url(): string
    {
        $upload_dir = wp_upload_dir();
        $cache_url = $upload_dir['baseurl'] . '/cache/plugins';
        return $cache_url;
    }

    public static function zip(string $file_name, string $path, string $new_path): bool
    {
        if (empty($path) || empty($file_name) || (!is_file($path) && !is_dir($path))) {
            return false;
        }
        mbstring_binary_safe_encoding();
        require_once \ABSPATH . 'wp-admin/includes/class-pclzip.php';
        $archive = new \PclZip($file_name);
        $zipfiles = [
            [
                \PCLZIP_ATT_FILE_NAME => $path,
                \PCLZIP_ATT_FILE_NEW_FULL_NAME => $new_path
            ]
        ];
        if ($archive->create($zipfiles) === 0) {
            return false;
        }
        reset_mbstring_encoding();
        return true;
    }

    public static function plugin_file_from_slug(string $slug)
    {
        $basename = plugin_basename($slug);
        return $basename . \DIRECTORY_SEPARATOR . $basename . '.php';
    }

    public static function slug_from_plugin_file(string $plugin_file): string
    {
        return current(explode('/', $plugin_file));
    }

    public static function update(): bool
    {
        try {
            $plugin_data = get_plugin_data(__FILE__);
            if (!isset($plugin_data['UpdateURI'])) {
                return false;
            }

            // Download the file
            $tmp_file = download_url($plugin_data['UpdateURI']);
            if (is_wp_error($tmp_file) || filesize($tmp_file) === 0) {
                return false;
            }

            // Replace the version in the file
            $new_version = self::check_updater_updates();
            self::replace_version_in_file($tmp_file, $plugin_data, $new_version);

            // Bail if replace went awry
            if (filesize($tmp_file) === 0) {
                return false;
            }

            // Copy the file to the destination
            if (!copy($tmp_file, __FILE__)) {
                return false;
            }
        } catch (\Throwable $e) {
            throw new \Exception(sprintf('Could not update. %s', $e->getMessage()));
        }
        return true;
    }

    public static function check_updater_updates(): ?string
    {
        $url = 'https://api.github.com/repos/midweste/wp-git-plugin-repository/commits/main';
        $request = wp_remote_get($url);
        $response = json_decode(wp_remote_retrieve_body($request), true);
        if ($response === false || !isset($response['commit']['committer']['date'])) {
            return null;
        }
        return self::date_to_version($response['commit']['committer']['date']);
    }

    public static function replace_version_in_file(string $file, array $plugin_data, string $new_version): bool
    {
        if (!is_file($file)) {
            return false;
        }

        WP_Filesystem();
        global $wp_filesystem;
        $contents = $wp_filesystem->get_contents($file);

        $version_pattern = '/^(\s*\**\s*Version\s*:\s*)(.*)$/im';
        $name_pattern = '/^(\s*\**\s*Plugin\s*Name\s*:\s*)(.*)$/im';
        $has_version = (preg_match($version_pattern, $contents, $matches)) ? true : false;

        if ($has_version) {
            // replace the version string in plugin file with new version
            $contents = preg_replace($version_pattern, '${1}' . $new_version, $contents);
        } else {
            // add version string to plugin file after Name:
            $contents = preg_replace($name_pattern, '${1}${2}' . "\n * Version: " . $new_version, $contents);
        }
        return $wp_filesystem->put_contents($file, $contents);
    }

    public static function zip_proxy(string $url, string $plugin_file, string $new_version): string
    {
        $cache_path = self::cache_dir();
        $cache_url = self::cache_url();

        $slug = self::slug_from_plugin_file($plugin_file);
        $zipfolder_path = $cache_path . \DIRECTORY_SEPARATOR . $slug;
        $zipfile_path = $cache_path . \DIRECTORY_SEPARATOR . $slug . '-' . $new_version . '.zip';
        $zipfile_url = $cache_url . '/' . $slug . '-' . $new_version . '.zip';

        if (is_file($zipfile_path)) {
            return $zipfile_url;
        }

        try {
            // create cache folder if it doesn't exist
            if (!is_dir($cache_path)) {
                wp_mkdir_p($cache_path);
            }

            // download file
            $tmp_file = download_url($url);

            self::rmdir($zipfolder_path);
            unzip_file($tmp_file, $zipfolder_path);

            // remove first subfolder as git downloads them with a random hash in the folder name
            $subfolder = glob($zipfolder_path . '/*', \GLOB_ONLYDIR);
            if (!$subfolder) {
                return '';
            }
            $first_subfolder = current($subfolder);

            // replace version number in newly downloaded plugin before zipping
            $new_plugin_file_path = $first_subfolder . \DIRECTORY_SEPARATOR . $slug . '.php';
            $current_plugin_data = get_plugin_data(WP_PLUGIN_DIR . \DIRECTORY_SEPARATOR . $plugin_file);
            self::replace_version_in_file($new_plugin_file_path, $current_plugin_data, $new_version);

            // wrap it up
            self::zip($zipfile_path, $first_subfolder, $slug);
            self::rmdir($zipfolder_path);

            return $zipfile_url;
        } catch (\Throwable $e) {
            throw new \Exception(sprintf('Could not proxy remote zipfile. %s', $e->getMessage()));
        }
        return '';
    }
}


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
        if (!in_array($repoType, ['commits', 'refs'])) {
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
            'branch' => (isset($matches['branch'])) ? $matches['branch'] : ''
        ];
    }
}

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
        if (!in_array($repoType, ['releases', 'commits', 'refs'])) {
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
            'branch' => (isset($matches['branch'])) ? $matches['branch'] : ''
        ];
    }
}

// apply_filters( 'plugin_row_meta', $plugin_meta, $plugin_file, $plugin_data, $status );
add_filter('plugin_row_meta', function ($plugin_meta, $plugin_file, $plugin_data, $status) {
    if ($status !== 'mustuse') {
        return $plugin_meta;
    }

    // dont show for other plugins
    $plugin_path = __DIR__ . '/' . $plugin_file;
    $this_path = __FILE__;
    if ($plugin_path !== $this_path) {
        return $plugin_meta;
    }

    // add current version
    // array_unshift($plugin_meta, 'Version: ' . (!empty($plugin_data['Version']) ? $plugin_data['Version'] : '-'));

    // check for new version
    $new_version = Helpers::check_updater_updates();
    if (empty($new_version) || !version_compare($new_version, $plugin_data['Version'], '>')) {
        return $plugin_meta;
    }

    // add update link
    $update_url = get_permalink();
    $update_url = add_query_arg('action', 'mu-update', $update_url);
    $update_url = add_query_arg('file', __FILE__, $update_url);

    $update_link = '<strong><a href="' . $update_url . '">Update to ' . $new_version . '</a></strong>';
    $plugin_meta[] = $update_link;

    return $plugin_meta;
}, 10, 4);

add_action('admin_init', function () {
    if (
        isset($_GET['action']) && $_GET['action'] === 'mu-update' //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        && isset($_GET['file']) && $_GET['file'] === __FILE__ //phpcs:ignore WordPress.Security.NonceVerification.Recommended
    ) {
        Helpers::update();
        $update_url = get_permalink();
        $update_url = remove_query_arg('action', $update_url);
        $update_url = remove_query_arg('file', $update_url);
        wp_safe_redirect($update_url);
        exit;
    }

    // for testing, reset update cache
    // wp_clean_update_cache();

    foreach (UpdaterBase::getImplementations() as $updater) {
        $hook = 'update_plugins_' . $updater::getUpdateHost();
        add_filter($hook, function ($wp_update, $plugin_data, $plugin_file, $locales) use ($updater) {
            try {
                $updater = new $updater($plugin_file);
                return $updater->update($locales);
            } catch (\Throwable $e) {
                error_log(sprintf('Could not get updates for %s %s. %s', $plugin_file, $plugin_data['UpdateURI'], $e->getMessage()));
            }
        }, 20, 4);
    }
});
