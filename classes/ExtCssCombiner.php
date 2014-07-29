<?php

namespace ExtAssets;

use Contao\File;
use Contao\Environment;
require_once TL_ROOT . "/system/modules/extassets/classes/vendor/lessphp/lessc.inc.php";

class ExtCssCombiner extends \Frontend
{

	protected $rewrite = false;

	protected $rewriteBootstrap = false;

	protected $arrData = array();

	protected $arrCss = array();

	protected $arrReturn = array();

	protected $mode = 'static';

	protected $variablesSrc;

	public static $userCssKey = 'usercss';

	public static $bootstrapCssKey = 'bootstrap';
	
	public static $bootstrapPrintCssKey = 'bootstrap-print';

	public static $bootstrapResponsiveCssKey = 'bootstrap-responsive';

	public static $fontAwesomeCssKey = 'font-awesome';

	protected $objUserCssFile; // Target File of combined less output
	
	protected $uriRoot;
	
	public $debug = false;

	public function __construct(ExtCssModel $objCss, $arrReturn = array())
	{
		parent::__construct();
		$this->loadDataContainer('tl_extcss');
		$this->arrData = $objCss->row();

		$this->mode = $GLOBALS['TL_CONFIG']['bypassCache'] ? 'none' : 'static';

		$this->arrReturn = $arrReturn;

		$this->checkModelUpdate();

		$this->variablesSrc = 'variables-' . $this->title . '.less';

		$this->objUserCssFile = new \File($this->getSrc($this->title . '.css'));;

		$this->uriRoot = (TL_ASSETS_URL ? TL_ASSETS_URL : Environment::get('url')) . '/assets/css/';
		
		if($this->debug)
		{
			$this->rewrite = true;
			$this->rewriteBootstrap = true;
		}

		if($this->addBootstrap)
		{
			$this->addBootstrapVariables();
			$this->addFontAwesomeCore();
			$this->addBootstrapPrintCss();
			$this->addBootstrapMixins();
			$this->addBootstrapAlerts();
			$this->addBootstrap();
			$this->addBootstrapUtilities();
		}

		if($this->addFontAwesome)
		{
			$this->addFontAwesomeVariables();
			$this->addFontAwesomeIcons();
			$this->addFontAwesomeMixins();
			$this->addFontAwesome();
		}
		
		$this->transformUserLessFilesToCss();

		$this->addCssFiles();
	}

	public function getUserCss()
	{
		$arrReturn = $this->arrReturn;

		if(is_array($this->arrCss) && !empty($this->arrCss) && ($this->rewrite || $this->rewriteBootstrap))
		{
			$lessc = new \lessc();
			$strCss = $lessc->compile(implode("\n", $this->arrCss));
			$this->objUserCssFile->write($strCss);
		}

		$arrReturn[self::$userCssKey][] = array
		(
			'src'	=> $this->objUserCssFile->value,
			'type'	=> 'all', // 'all' is required by print media css 
			'mode'	=> $this->mode,
			'hash'	=> $this->objUserCssFile->hash,
		);

		return $arrReturn;
	}


	protected function addCssFiles()
	{
		$objFiles = ExtCssFileModel::findMultipleByPid($this->id);

		if($objFiles === null) return false;

		while($objFiles->next())
		{
			$objFileModel = \FilesModel::findByPk($objFiles->src);

			if($objFileModel === null) continue;

			$objFile = new \File($objFileModel->path);

			if($objFile->size == 0) continue;

			if($this->isFileUpdated($objFile, $this->objUserCssFile) || $this->rewrite)
			{
				$this->rewrite = true;
			}

			$this->arrCss[$objFileModel->id] = $objFile->getContent();
		}
	}

	protected function addBootstrap()
	{
		$objFile = new \File($this->getBootstrapSrc('bootstrap.less'));
		$objTarget = new \File($this->getBootstrapCustomSrc('bootstrap-' . $this->title .  '.less'));
		$objOut = new \File($this->getSrc('bootstrap-' . $this->title .  '.css'), true);

		if($this->rewriteBootstrap || !$objOut->exists())
		{
			$strCss = $objFile->getContent();

			$strCss = str_replace('@import "', '@import "../', $strCss);


			if($this->bootstrapVariablesSRC)
			{
				$strCss = str_replace('../variables.less', $this->variablesSrc, $strCss);
			}

			$objTarget->write($strCss);
			$objTarget->close();

			$lessc = new \lessc();
			$strCss = $lessc->compileFile(TL_ROOT . '/' . $objTarget->value, TL_ROOT . '/' . $objOut->value);

			$objOut = new \File($objOut->value);
		}

		$this->arrReturn[self::$bootstrapCssKey][] = array
		(
			'src'	=> $objOut->value,
			'type'	=> 'all', // 'all' is required for .hidden-print class, not 'screen'
			'mode'	=> $this->mode,
			'hash'	=> $objOut->hash,
		);
	}

	/**
	 * variables.less must not be changed
	 * use custom bootstrapVariablesSRC to change variables
	 */
	protected function addBootstrapVariables()
	{
		$objFile = new \File($this->getBootstrapSrc('variables.less'));

		if($objFile->size > 0)
		{
			$this->arrCss['variables'] = $objFile->getContent();
		}

		if(!$this->bootstrapVariablesSRC) return false;

		$objTarget = new \File($this->getBootstrapCustomSrc($this->variablesSrc));

		// overwrite bootstrap variables with custom variables
		$objFileModel = \FilesModel::findByPk($this->bootstrapVariablesSRC);

		if($objFileModel !== false)
		{
			$objFile = new \File($objFileModel->path);

			if($this->isFileUpdated($objFile, $objTarget))
			{
				$this->rewrite = true;
				$this->rewriteBootstrap = true;
				$this->arrCss['variables'] .= "\n" . $objFile->getContent();
			} else {
				$this->arrCss['variables'] .= "\n" . $objFile->getContent();
			}
		}

		if($this->rewriteBootstrap)
		{
			$objTarget->write($this->arrCss['variables']);
		}
	}

	/**
	 * mixins.less must not be changed, no hash check
	 */
	protected function addBootstrapMixins()
	{
        $objFile = new \File($this->getBootstrapSrc('mixins.less'));

        if(str_replace('v', '', BOOTSTRAPVERSION) >= '3.2.0')
        {
            preg_match_all('/@import "(.*)";/', $objFile->getContent(), $arrImports);

            if(is_array($arrImports[1]))
            {
                foreach($arrImports[1] as $strFile)
                {
                    if(!file_exists(TL_ROOT . '/' . BOOTSTRAPLESSDIR . '/' . $strFile)) continue;

                    $objMixinFile = new \File(BOOTSTRAPLESSDIR . '/' . $strFile);
                    $this->arrCss['mixins'] .= $objMixinFile->getContent();
                }
            }

            return;
        }


        if($objFile->size > 0)
		{
			$this->arrCss['mixins'] = $objFile->getContent();
		}
	}

	/**
	 * alerts.less must not be changed, no hash check
	 */
	protected function addBootstrapAlerts()
	{
		$objFile = new \File($this->getBootstrapSrc('alerts.less'));

		if($objFile->size > 0)
		{
			$this->arrCss['alerts'] = $objFile->getContent();
		}
	}


	protected function addBootstrapUtilities()
	{
		$objFile = new \File($this->getBootstrapSrc('utilities.less'));

		if($objFile->size > 0)
		{
			$this->arrCss['utilities'] = $objFile->getContent();
		}
	}

	protected function addBootstrapPrintCss()
	{
		$objFile = new \File($this->getBootstrapSrc('print.less'));
		$objTarget = new \File($this->getSrc('bootstrap-print.css'));
		
		if($this->isFileUpdated($objFile, $objTarget) || $this->rewrite)
		{
			$options = array('cache_dir'=> TL_ROOT . '/' . LESSCSSCACHEDIR);
				
			$parser = new \Less_Parser($options);
			$parser->parseFile(TL_ROOT . '/' . $objFile->value, $this->uriRoot);
			$objTarget->write($parser->getCss());
			$objTarget->close();
		}

		$this->arrReturn[self::$bootstrapPrintCssKey][] = array
		(
				'src'	=> $objTarget->value,
				'type'	=> 'print',
				'mode'	=> $this->mode,
				'hash'	=> $objTarget->hash,
		);
	}
	
	protected function addFontAwesomeVariables()
	{
		$objFile = new \File($this->getFontAwesomeSrc('variables.less'));
		$objTarget = new \File($this->getFontAwesomeCustomSrc($this->variablesSrc), true);

		if($objFile->size > 0)
		{
			$this->arrCss['variables-fontawesome'] = $objFile->getContent();
			// change font path
			$this->arrCss['variables-fontawesome'] = str_replace("../fonts", '/' . rtrim(FONTAWESOMEFONTDIR, '/'), $this->arrCss['variables-fontawesome']);
		}

		if(!$objTarget->exists() || $objTarget->size == 0)
		{
			\File::putContent($this->getFontAwesomeCustomSrc($this->variablesSrc), $this->arrCss['variables-fontawesome']);
		}
	}

	protected function addFontAwesomeCore()
	{
		$objFile = new \File($this->getFontAwesomeSrc('core.less'));
	
		if($objFile->size > 0)
		{
			$this->arrCss['core-fontawesome'] = $objFile->getContent();
		}
	}
	
	protected function addFontAwesomeMixins()
	{
		$objFile = new \File($this->getFontAwesomeSrc('mixins.less'));

		if($objFile->size > 0)
		{
			$this->arrCss['mixins-fontawesome'] = $objFile->getContent();
		}
	}
	
	/**
	 * Font-Awesome 4.0 support for less icons
	 */
	protected function addFontAwesomeIcons()
	{
		$objFile = new \File($this->getFontAwesomeSrc('icons.less'));
	
		if($objFile->size > 0)
		{
			$this->arrCss['icons-fontawesome'] = $objFile->getContent();
		}
	}

	protected function addFontAwesome()
	{
		$objFile = new \File($this->getFontAwesomeSrc('font-awesome.less'));
		$objTarget = new \File($this->getFontAwesomeCustomSrc('font-awesome-' . $this->title .  '.less'));
		$objOut = new \File($this->getSrc('font-awesome-' . $this->title .  '.css'), true);

		if($this->rewrite || !$objOut->exists() || $objTarget->size == 0 || $objOut->size == 0 )
		{
			$strCss = $objFile->getContent();
			
			
			$strCss = str_replace('@import "', '@import "../', $strCss);
			$strCss = str_replace('../variables.less', $this->variablesSrc, $strCss);
			
			$objTarget->write($strCss);
			$objTarget->close();
			
			$lessc = new \lessc();
			$strCss = $lessc->compileFile(TL_ROOT . '/' . $objTarget->value, TL_ROOT . '/' . $objOut->value);

			$objOut = new \File($objOut->value);
		}

		$this->arrReturn[self::$fontAwesomeCssKey][] = array
		(
				'src'	=> $objOut->value,
				'type'	=> 'all',
				'mode'	=> $this->mode,
				'hash'	=> $objOut->hash,
		);
	}
	
	protected function transformUserLessFilesToCss()
	{
		if(!is_array($GLOBALS['TL_USER_CSS']) || empty($GLOBALS['TL_USER_CSS'])) return false;
			
		foreach($GLOBALS['TL_USER_CSS'] as $key => $css)
		{
			$content = '';
			
			$arrCss = trimsplit('|', $css);
			
			$objFile = new \File($arrCss[0]);
			
			if($this->addBootstrap)
			{
				$content .= $this->arrCss['variables'];
				$content .= $this->arrCss['utilities'];
				$content .= $this->arrCss['mixins'];
			}
			
			
			$arrCss[0] = 'assets/css/' . $objFile->filename . '.css';
			
			$objTarget = new \File($arrCss[0], false);
			
			// must be set, otherwise the contao css combiner will not regenerate the css
			$arrCss[3] = $objTarget->hash;
			
			if(!$this->isFileUpdated($objFile, $objTarget))
			{
				$GLOBALS['TL_USER_CSS'][$key] =  implode('|', $arrCss);
				continue;
			}
			
			$objTarget->delete();
			
			$objTarget = new \File($arrCss[0]);
			$parser = new \Less_Parser();
			$parser->parseFile(TL_ROOT . '/' . $objFile->value, $this->uriRoot);
			$parser->parse($content);
			$objTarget->write($parser->getCss());
			$objTarget->close();
			
			// must be updated, otherwise the contao css combiner will not regenerate the css
			$arrCss[3] = $objTarget->hash;
			
			$GLOBALS['TL_USER_CSS'][$key] = implode('|', $arrCss);
		}
	}

	/**
	 * If ExtCss Model has changed set:
	 * - $this->rewrite
	 * - $this->rewriteBootstrap
	 * to true. Bootstrap and default Files update will be forced.
	 */
	protected function checkModelUpdate()
	{
		$objFile = new ExtHashFile($this->getSrc($this->title));

		$strHash = $objFile->getHash();

		// hash not set yet, file recently created new
		if(empty($strHash) || $this->tstamp > $strHash)
		{
			$this->rewrite = true;
			$this->rewriteBootstrap = true;
			$objFile->write($this->tstamp);
		}
	}

	protected function isFileUpdated(\File $objFile, \File $objTarget)
	{
		return($objFile->size > 0 && ($this->rewrite || $this->rewriteBootstrap || $objFile->mtime > $objTarget->mtime));
	}

	public function __get($strKey)
	{
		if (isset($this->arrData[$strKey]))
		{
			return $this->arrData[$strKey];
		}

		return parent::__get($strKey);
	}

	protected function getSrc($src)
	{
		return CSSDIR . $src;
	}

	protected function getBootstrapSrc($src)
	{
		return BOOTSTRAPLESSDIR . $src;
	}

	protected function getBootstrapCustomSrc($src)
	{
		return BOOTSTRAPLESSCUSTOMDIR . $src;
	}

	protected function getFontAwesomeSrc($src)
	{
		return FONTAWESOMELESSDIR . $src;
	}
	
	protected function getFontAwesomeCustomSrc($src)
	{
		return FONTAWESOMELESSCUSTOMDIR . $src;
	}

}