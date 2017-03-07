<?php
/**
 * Akeeba Build Tools
 *
 * @package        buildfiles
 * @license        GPL v3
 * @copyright      2010-2017 Akeeba Ltd
 */

namespace Akeeba\LinkLibrary\Scanner;

use Akeeba\LinkLibrary\LinkHelper;
use Akeeba\LinkLibrary\MapResult;
use Akeeba\LinkLibrary\ScanResult;
use DirectoryIterator;
use DOMDocument;
use Symfony\Component\Yaml\Exception\RuntimeException;

abstract class AbstractScanner implements ScannerInterface
{
	/**
	 * The absolute path to the extension's root folder.
	 *
	 * @var   string
	 */
	protected $extensionRoot = '';

	/**
	 * The absolute path to the extension's language folder.
	 *
	 * @var   string
	 */
	protected $languageRoot = '';

	/**
	 * The absolute path to the target Joomla! site's root.
	 *
	 * @var   string
	 */
	protected $siteRoot = '';

	/**
	 * The XML manifest of the extension
	 *
	 * @var   DOMDocument
	 */
	protected $xmlManifest = null;

	/**
	 * The "type" attribute the XML manifest's root node must have.
	 *
	 * @var   string
	 */
	protected $manifestExtensionType = '';

	/**
	 * The results of scanning the extension
	 *
	 * @var   ScanResult
	 */
	private $scanResult = null;

	/**
	 * The results of mapping the scanned extension folders to a site root
	 *
	 * @var   MapResult
	 */
	private $mapResult = null;

	/**
	 * Constructor.
	 *
	 * The languageRoot is optional and applies only if the languages are stored in a directory other than the one
	 * specified in the extension's XML file.
	 *
	 * @param   string  $extensionRoot  The absolute path to the extension's root folder
	 * @param   string  $languageRoot   The absolute path to the extension's language folder (optional)
	 */
	public function __construct($extensionRoot, $languageRoot = null)
	{
		if (!is_dir($extensionRoot) || !is_readable($extensionRoot))
		{
			throw new RuntimeException("Cannot scan extension in non-existent or unreadable folder $this->extensionRoot");
		}

		$this->extensionRoot = $extensionRoot;

		if (!empty($languageRoot))
		{
			$this->languageRoot = $languageRoot;

			if (!is_dir($languageRoot) || !is_readable($languageRoot))
			{
				throw new RuntimeException("Cannot scan translations in non-existent or unreadable folder $this->extensionRoot");
			}
		}
	}

	/**
	 * Find the XML manifest in an extension's directory and return the DOMDocument for it.
	 *
	 * @param   string   $root           The folder to look into.
	 * @param   string   $extensionType  The expected type of the extension, null to not perform this check.
	 *
	 * @return  DOMDocument|null  The DOMDocument for the XML manifest or null if none was found.
	 */
	protected static function findXmlManifest($root, $extensionType = null)
	{
		foreach (new DirectoryIterator($root) as $fileInfo)
		{
			if ($fileInfo->isDot() || !$fileInfo->isFile())
			{
				continue;
			}

			if ($fileInfo->getExtension() != 'xml')
			{
				continue;
			}

			$xmlDoc = new DOMDocument;
			$xmlDoc->load($fileInfo->getRealPath(), LIBXML_NOBLANKS | LIBXML_NOCDATA | LIBXML_NOENT | LIBXML_NONET);

			$rootNodes    = $xmlDoc->getElementsByTagname('extension');

			if ($rootNodes->length < 1)
			{
				unset($xmlDoc);
				continue;
			}

			$root = $rootNodes->item(0);

			if (!$root->hasAttributes())
			{
				unset($xmlDoc);
				continue;
			}

			if (!empty($extensionType) && ($root->getAttribute('type') != $extensionType))
			{
				unset($xmlDoc);
				continue;
			}

			return $xmlDoc;
		}

		return null;
	}

	/**
	 * Return the XML manifest for this extension
	 *
	 * @return  DOMDocument|null
	 */
	protected function getXMLManifest()
	{
		if (is_null($this->xmlManifest))
		{
			$this->xmlManifest = self::findXmlManifest($this->extensionRoot, $this->manifestExtensionType);
		}

		if (is_null($this->xmlManifest))
		{
			throw new RuntimeException("Cannot find manifest for extension in $this->extensionRoot");
		}

		return $this->xmlManifest;
	}

	/**
	 * Returns the extension root folder
	 *
	 * @return  string
	 */
	public final function getExtensionRoot(): string
	{
		return $this->extensionRoot;
	}

	/**
	 * Returns the language root folder
	 *
	 * @return  string
	 */
	public final function getLanguageRoot(): string
	{
		return $this->languageRoot;
	}

	/**
	 * Get the currently configured Joomla! site root path
	 *
	 * @return  string
	 */
	public final function getSiteRoot(): string
	{
		return $this->siteRoot;
	}

	/**
	 * Set the Joomla! site root path
	 *
	 * @param   string  $path
	 *
	 * @return  void
	 */
	public final function setSiteRoot(string $path)
	{
		$path = realpath($path);

		if ($this->siteRoot != $path)
		{
			$this->mapResult = null;
		}

		$this->siteRoot = $path;
	}

	/**
	 * Retrieves the scan results
	 *
	 * @return  ScanResult
	 */
	public final function getScanResults(): ScanResult
	{
		if (empty($this->scanResult))
		{
			$this->scan();
		}

		return $this->scanResult;
	}

	/**
	 * Returns the link map. If the link map does not exist it will be created first.
	 *
	 * @return  MapResult
	 */
	public final function getLinkMap(): MapResult
	{
		if (empty($this->mapResult))
		{
			$this->map();
		}

		return $this->mapResult;
	}

	/**
	 * Removes the link map targets. If the link map does not exist it will be created first.
	 *
	 * IMPORTANT: This removes the map targets no matter if they are links or real folders / files.
	 *
	 * @return  void
	 */
	public final function unlink()
	{
		$map = $this->getLinkMap();

		if (!empty($map->dirs)) foreach($map->dirs as $from => $to)
		{
			LinkHelper::recursiveUnlink($to);
		}

		if (!empty($map->files)) foreach($map->files as $from => $to)
		{
			LinkHelper::unlink($to);
		}

		if (!empty($map->hardfiles)) foreach($map->hardfiles as $from => $to)
		{
			LinkHelper::unlink($to);
		}
	}

	/**
	 * Links the map targets. If the link map does not exist it will be created first.
	 *
	 * @return  void
	 */
	public final function relink()
	{
		$map = $this->getLinkMap();

		if (!empty($map->dirs)) foreach($map->dirs as $from => $to)
		{
			LinkHelper::symlink($from, $to);
		}

		if (!empty($map->files)) foreach($map->files as $from => $to)
		{
			LinkHelper::symlink($from, $to);
		}

		if (!empty($map->hardfiles)) foreach($map->hardfiles as $from => $to)
		{
			LinkHelper::hardlink($from, $to);
		}
	}

}