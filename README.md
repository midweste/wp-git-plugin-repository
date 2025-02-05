# Wordpress Git Plugin Repository

Use a Github/Bitbucket public repo api as a plugin repository for Wordpress plugin updates.
Works with Wordpress standard and automatic updates.
Specify Update URI: in your plugin comment block to pull from remote repositories on update.
**Coming soon - handling private repos.**

Version string in the plugin is added or replaced with:

- Datetime version when using commits as source
- Release when using releases
- Tag when using tags

## To Install

### As a normal plugin

- Unzip and add to the plugins folder

## To Update Plugins

Add **Update URI:** in your plugin comment block.

### GithubUpdater Plugin Updater

Use releases for updates:

`Update URI: https://api.github.com/repos/{$repoOwner}/{$repoName}/releases`

Use a branch for updates:

`Update URI: https://api.github.com/repos/{$repoOwner}/{$repoName}/commits/{$repoBranch}`

Use tags for updates:

`Update URI: https://api.github.com/repos/{$repoOwner}/{$repoName}/ref/tags`

### Bitbucket Plugin Updater

Use a branch for updates:

`Update URI: https://api.bitbucket.org/2.0/repositories/{$repoOwner}/{$repoName}/commits/{$repoBranch}`

Use tags for updates:

`Update URI: https://api.bitbucket.org/2.0/repositories/{$repoOwner}/{$repoName}/ref/tags`
