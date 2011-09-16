<?php
/*
* 2007-2011 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Open Software License (OSL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/osl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2011 PrestaShop SA
*  @version  Release: $Revision$
*  @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

class UpgraderCore
{
	const DEFAULT_CHECK_VERSION_DELAY_HOURS = 24;
	public $rss_version_link = 'http://www.prestashop.com/xml/version.xml';
	public $rss_md5file_link_dir = 'http://localhost/xml/md5/';
	/**
	 * link contains hte url where to download the file
	 * 
	 * @var string 
	 */
	private $need_upgrade = false;
	private $changed_files = array();
	private $missing_files = array();

	public $version_name;
	public $version_num;
	public $link;
	public $autoupgrade;
	public $autoupgrade_module;
	public $changelog;
	public $md5;

	public function __get($var)
	{
		if ($var == 'need_upgrade')
			return $this->isLastVersion();
	}

	/**
	 * downloadLast download the last version of PrestaShop and save it in $dest/$filename
	 * 
	 * @param string $dest directory where to save the file
	 * @param string $filename new filename
	 * @return boolean
	 *
	 * @TODO ftp if copy is not possible (safe_mode for example)
	 */
	public function downloadLast($dest, $filename = 'prestashop.zip')
	{
		if (empty($this->link))
			$this->checkPSVersion();

		if (@copy($this->link, realpath($dest).DIRECTORY_SEPARATOR.$filename))
			return true;
		else
			return false;
	}
	public function isLastVersion()
	{
		if (empty($this->link))
			$this->checkPSVersion();

		return $this->need_upgrade;

	}

	/**
	 * checkPSVersion ask to prestashop.com if there is a new version. return an array if yes, false otherwise
	 * 
	 * @return mixed
	 */
	public function checkPSVersion($force = false)
	{
		if (empty($this->link))
		{
			if (class_exists('Configuration'))
				$last_check = Configuration::get('PS_LAST_VERSION_CHECK');
			else
				$last_check = 0;
			// if we use the autoupgrade process, we will never refresh it
			// except if no check has been done before
			if ($force || ($last_check < time() - (3600 * Upgrader::DEFAULT_CHECK_VERSION_DELAY_HOURS)))
			{
				libxml_set_streams_context(stream_context_create(array('http' => array('timeout' => 3))));
				if ($feed = @simplexml_load_file($this->rss_version_link))
				{

					$this->version_name = (string)$feed->version->name;
					$this->version_num = (string)$feed->version->num;
					$this->link = (string)$feed->download->link;
					$this->md5 = (string)$feed->download->md5;
					$this->changelog = (string)$feed->download->changelog;
					$this->autoupgrade = (int)$feed->autoupgrade;
					$this->autoupgrade_module = (int)$feed->autoupgrade_module;
					$this->desc = (string)$feed->desc;
					$config_last_version = array(
						'name' => $this->version_name,
						'num' => $this->version_num,
						'link' => $this->link,
						'md5' => $this->md5,
						'autoupgrade' => $this->autoupgrade,
						'autoupgrade_module' => $this->autoupgrade_module,
						'changelog' => $this->changelog,
						'desc' => $this->desc
					);
					if (class_exists('Configuration'))
					{
						Configuration::updateValue('PS_LAST_VERSION', serialize($config_last_version));
						Configuration::updateValue('PS_LAST_VERSION_CHECK', time());
					}
				}
			}
			else
			{
				$last_version_check = @unserialize(Configuration::get('PS_LAST_VERSION'));
				$this->version_name = $last_version_check['name'];
				$this->version_num = $last_version_check['num'];
				$this->link = $last_version_check['link'];
				$this->autoupgrade = $last_version_check['autoupgrade'];
				$this->autoupgrade_module = $last_version_check['autoupgrade_module'];
				$this->md5 = $last_version_check['md5'];
				$this->desc = $last_version_check['desc'];
				$this->changelog = $last_version_check['changelog'];
			}
		}
		// retro-compatibility :
		// return array(name,link) if you don't use the last version
		// false otherwise
		if (version_compare(_PS_VERSION_, $this->version_num, '<'))
		{
			$this->need_upgrade = true;
			return array('name' => $this->version_name, 'link' => $this->link);
		}
		else
			return false;
	}

	public function getChangedFilesList()
	{
		if (count($this->changed_files) == 0)
		{
			$checksum = @simplexml_load_file($this->rss_md5file_link_dir._PS_VERSION_.'.xml');
			if ($checksum === false)
				return false;
			else
				$this->browseXmlAndCompare($checksum->ps_root_dir[0]);
		}
		return $this->changed_files;
	}
	protected function addChangedFile($path)
	{
		$this->version_is_modified = true;
		$this->changed_files[] = $path;
		//array_unique($this->changed_files);
	}

	protected function addMissingFile($path)
	{
		$this->version_is_modified = true;
		$this->missing_files[] = $path;
		//array_unique($this->missingFile);
	}

	protected function browseXmlAndCompare($node, &$current_path = array(), $level = 1)
	{
		foreach ($node as $key => $child)
		{
			if (is_object($child) && $child->getName() == 'dir')
			{
				$current_path[$level] = (string)$child['name'];
				$this->browseXmlAndCompare($child, $current_path, $level + 1);
			}
			else if (is_object($child) && $child->getName() == 'md5file')
			{
					$path = _PS_ROOT_DIR_.DIRECTORY_SEPARATOR;
					for ($i = 1; $i < $level; $i++)
						$path .= $current_path[$i].'/';
					$path .= (string)$child['name'];
					$path = str_replace('ps_root_dir', _PS_ROOT_DIR_, $path);
					if (!file_exists($path))
						$this->addMissingFile($path);
					else if (!$this->compareChecksum($path, (string)$child))
						$this->addChangedFile($path);
					// else, file is original (and ok)
			}
		}
	}

	protected function compareChecksum($path, $original_sum)
	{
		if (md5_file($path) == $original_sum)
			return true;
		return false;
	}

	public function isAuthenticPrestashopVersion()
	{
		$this->getChangedFilesList();
		return !$this->version_is_modified;
	}

}
