<?php declare(strict_types=1);
/**
 * @author    Osiozekhai Aliu
 * @package   Osio_MagentoAutoPatch
 * @copyright Copyright (c) 2024 Osio
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Osio\MagentoAutoPatch\Model;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Serialize\Serializer\Json;
use Symfony\Component\Process\Process;

class Composer extends AbstractProcess
{

    /**
     * @var DirectoryList
     */
    private DirectoryList $directoryList;

    /**
     * @var array
     */
    private array $composerJson;

    /**
     * @var Json
     */
    private Json $json;

    /**
     * @var File
     */
    private File $file;

    /**
     * @param DirectoryList $directoryList
     * @param Json          $json
     * @param File          $file
     */
    public function __construct(
        DirectoryList $directoryList,
        Json          $json,
        File          $file
    ) {
        $this->directoryList = $directoryList;
        $this->json = $json;
        $this->file = $file;
    }

    /**
     * Get Composer Path
     *
     * @return string
     */
    public function getComposerPath(): string
    {
        return "{$this->directoryList->getRoot()}/composer.json";
    }

    /**
     * Check for versions
     *
     * @return Process
     */
    public function hasVersions(): Process
    {
        return $this->runCommand("composer show --outdated {$this->whichMagento()} --all -n");
    }

    /**
     * Check if cloud or community Magento version
     *
     * @return string|null
     */
    public function whichMagento(): ?string
    {
        $magentoPackages = [
        'magento/magento-cloud-metapackage',
        'magento/product-community-edition'
        ];

        foreach ($magentoPackages as $package) {
            if (array_key_exists($package, $this->composerJson['require'])) {
                return $package;
            }
        }

        return null;
    }

    /**
     * Get Module Version
     *
     * @return string
     * @throws FileSystemException
     */
    public function getVersion(): string
    {
        if ($this->file->isExists($this->getComposerPath())) {
            $this->composerJson = $this->json->unserialize(
                $this->file->fileGetContents($this->getComposerPath())
            );
        }

        return $this->composerJson['require'][$this->whichMagento()] ?? 'Unknown';
    }

    /**
     * Download latets version
     *
     * @return bool
     * @throws FileSystemException
     */
    public function downloadLatestVersion(): bool
    {
        return $this->runCommand("composer require {$this->whichMagento()}:{$this->getLatest()} -n")
            ->isSuccessful();
    }

    /**
     * Get latest minor patch version
     *
     * @return string|null
     * @throws FileSystemException
     */
    public function getLatest(): ?string
    {
        $currentVersion = $this->getVersion();
        $baseVersion = $this->extractBaseVersion($currentVersion);

        $availableVersions = $this->fetchOutdatedVersions();
        if (empty($availableVersions)) {
            return null;
        }

        $filteredVersions = $this->filterVersions($availableVersions, $baseVersion);
        if (empty($filteredVersions)) {
            return null;
        }

        $latestPatch = $this->getLatestPatchVersion($filteredVersions);

        return $this->isNewerVersion($currentVersion, $latestPatch) ? $latestPatch : null;
    }

    /**
     *  Extracts the base version (major.minor.patch) from the current version.
     *
     * @param  string $currentVersion
     * @return string
     */
    private function extractBaseVersion(string $currentVersion): string
    {
        $versionParts = explode('-', $currentVersion);
        return $versionParts[0]; // Return base version like '2.4.6'
    }

    /**
     * Fetches all outdated versions from the composer show output.
     *
     * @return array
     */
    private function fetchOutdatedVersions(): array
    {
        preg_match_all('/\d+\.\d+\.\d+-p\d+/m', $this->hasVersions()->getOutput(), $matches);

        return $matches[0] ?? [];
    }

    /**
     * Filters available versions to match the current major.minor.patch version.
     *
     * @param  array  $availableVersions
     * @param  string $baseVersion
     * @return array
     */
    private function filterVersions(array $availableVersions, string $baseVersion): array
    {
        return array_filter(
            $availableVersions,
            function ($version) use ($baseVersion) {
                return preg_match("/^$baseVersion-p\d+$/", $version); // Match the base version with patch
            }
        );
    }

    /**
     * Sorts the filtered versions and returns the latest patch version.
     *
     * @param  array $filteredVersions
     * @return string
     */
    private function getLatestPatchVersion(array $filteredVersions): string
    {
        usort($filteredVersions, 'version_compare');
        return end($filteredVersions); // Get the highest patch version
    }

    /**
     * Compares the current version with the latest patch version.
     *
     * @param  string $currentVersion
     * @param  string $latestPatch
     * @return bool
     */
    private function isNewerVersion(string $currentVersion, string $latestPatch): bool
    {
        return version_compare($currentVersion, $latestPatch, '<');
    }
}
