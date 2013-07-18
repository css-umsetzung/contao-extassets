<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (C) 2005-2013 Leo Feyer
 *
 * @package   ExtAssets
 * @author    r.kaltofen@heimrich-hannot.de
 * @license   GNU/LGPL
 * @copyright Heimrich & Hannot GmbH
 */


/**
 * Namespace
 */
namespace ExtAssets;

use Contao\File;

use Template;
use FrontendTemplate;
use ExtJsModel;
use ExtJsFileModel;


class ExtJs extends \Frontend
{

	/**
	 * Singleton
	 */
	private static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return \ExtJs\ExtJs
	 */
	public static function getInstance()
	{
		if (self::$instance == null)
		{
			self::$instance = new ExtJs();
		}
		return self::$instance;
	}

	public function hookReplaceDynamicScriptTags($strBuffer)
	{
		global $objPage;

		if(!$objPage) return $strBuffer;

		$objLayout = \LayoutModel::findByPk($objPage->layout);

		if(!$objLayout) return $strBuffer;

		// the dynamic script replacement array
		$arrReplace = array();

		$this->parseExtJs($objLayout, $arrReplace);

		return $strBuffer;
	}

	protected function parseExtJs($objLayout, &$arrReplace)
	{
		$arrJs = array();

		$objJs = ExtJsModel::findMultipleByIds(deserialize($objLayout->extjs));

		if($objJs === null) return false;

		while($objJs->next())
		{
			$objFiles = ExtJsFileModel::findMultipleByPid($objJs->id);

			while($objFiles->next())
			{
				$objFile = \FilesModel::findByPk($objFiles->src);
				if(!file_exists(TL_ROOT .'/'. $objFile->path)) continue;
				$js .= file_get_contents($objFile->path) . "\n";
			}

			// TODO: Refactor Js Generation
			$target = '/assets/js/' . $objJs->title . '.js';

			$rewrite = true;
			$version = md5($css);

			if(file_exists(TL_ROOT . $target))
			{
				$targetFile = new File($target);
				$rewrite = !($version == $targetFile->hash);
			}

			if($rewrite)
			{
				file_put_contents(TL_ROOT . $target, $js);
			}

			// TODO: add css minimizer option for extcss group
			$mode = $GLOBALS['TL_CONFIG']['bypassCache'] ? 'none' : 'static';

			$arrJs[] = "$target|$mode";
		}

		// HOOK: add custom css
		if (isset($GLOBALS['TL_HOOKS']['parseExtJs']) && is_array($GLOBALS['TL_HOOKS']['parseExtJs']))
		{
			foreach ($GLOBALS['TL_HOOKS']['parseExtJs'] as $callback)
			{
				$arrJs = static::importStatic($callback[0])->$callback[1]($arrJs);
			}
		}

		if($objJs->addBootstrap)
		{
			$arrJs = $this->addTwitterBootstrap($arrJs);
		}

		global $objPage;

		$blnXhtml = ($objPage->outputFormat == 'xhtml');

		// Add the internal scripts
		if (!empty($arrJs) && is_array($arrJs))
		{
			$objCombiner = new \Combiner();

			foreach (array_unique($arrJs) as $javascript)
			{
				list($javascript, $mode) = explode('|', $javascript);

				if ($mode == 'static')
				{
					$objCombiner->add($javascript, filemtime(TL_ROOT . '/' . $javascript));
				}
				else
				{
					$arrScripts[] = '<script' . ($blnXhtml ? ' type="text/javascript"' : '') . ' src="' . static::addStaticUrlTo($javascript) . '"></script>' . "\n";
				}
			}

			// Create the aggregated script and add it before the non-static scripts (see #4890)
			if ($objCombiner->hasEntries())
			{
				$arrScripts = '<script' . ($blnXhtml ? ' type="text/javascript"' : '') . ' src="' . $objCombiner->getCombinedFile() . '"></script>' . "\n" . $strScripts;
			}
		}

		// inject extjs before other plugins, otherwise bootstrap may not work
		$GLOBALS['TL_JQUERY'] = is_array($GLOBALS['TL_JQUERY']) ? array_merge($arrScripts, $GLOBALS['TL_JQUERY']) : $arrScripts;
	}

	/*
	 * TODO:
	* - install via runonce
	*/
	public function addTwitterBootstrap($arrJs)
	{
		$in = "/assets/bootstrap/js/bootstrap.js";

		if(!file_exists(TL_ROOT . $in)) return $arrJs;

		// TODO: add css minimizer option for extcss group
		$mode = $GLOBALS['TL_CONFIG']['bypassCache'] ? 'none' : 'static';

		array_insert($arrJs, -1, "$in|$mode");

		return $arrJs;
	}

}