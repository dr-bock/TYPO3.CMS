<?php
namespace TYPO3\CMS\Filelist\Controller;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Backend\Template\DocumentTemplate;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\Utility\IconUtility;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\Exception;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\Utility\ListUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\File\ExtendedFileUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Filelist\FileList;

/**
 * Script Class for creating the list of files in the File > Filelist module
 */
class FileListController {

	/**
	* Module configuration
	*
	* @var array
	* @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8. The Module gets configured by ExtensionManagementUtility::addModule() in ext_tables.php
	*/
	public $MCONF = array();

	/**
	 * @var array
	 */
	public $MOD_MENU = array();

	/**
	 * @var array
	 */
	public $MOD_SETTINGS = array();

	/**
	 * Accumulated HTML output
	 *
	 * @var string
	 */
	public $content;

	/**
	 * Document template object
	 *
	 * @var DocumentTemplate
	 */
	public $doc;

	/**
	 * "id" -> the path to list.
	 *
	 * @var string
	 */
	public $id;

	/**
	 * @var \TYPO3\CMS\Core\Resource\Folder
	 */
	protected $folderObject;

	/**
	 * @var FlashMessage
	 */
	protected $errorMessage;

	/**
	 * Pointer to listing
	 *
	 * @var int
	 */
	public $pointer;

	/**
	 * "Table"
	 *
	 * @var string
	 */
	public $table;

	/**
	 * Thumbnail mode.
	 *
	 * @var string
	 */
	public $imagemode;

	/**
	 * @var string
	 */
	public $cmd;

	/**
	 * Defines behaviour when uploading files with names that already exist; possible value are 'cancel', 'replace', 'changeName'
	 *
	 * @var string
	 */
	protected $overwriteExistingFiles;

	/**
	 * The filelist object
	 *
	 * @var FileList
	 */
	public $filelist = NULL;

	/**
	 * The name of the module
	 *
	 * @var string
	 */
	protected $moduleName = 'file_list';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->getLanguageService()->includeLLFile('EXT:lang/locallang_mod_file_list.xlf');
		$this->getLanguageService()->includeLLFile('EXT:lang/locallang_misc.xlf');
	}

	/**
	 * Initialize variables, file object
	 * Incoming GET vars include id, pointer, table, imagemode
	 *
	 * @return void
	 * @throws \RuntimeException
	 * @throws Exception\InsufficientFolderAccessPermissionsException
	 */
	public function init() {
		// Setting GPvars:
		$this->id = ($combinedIdentifier = GeneralUtility::_GP('id'));
		$this->pointer = GeneralUtility::_GP('pointer');
		$this->table = GeneralUtility::_GP('table');
		$this->imagemode = GeneralUtility::_GP('imagemode');
		$this->cmd = GeneralUtility::_GP('cmd');
		$this->overwriteExistingFiles = GeneralUtility::_GP('overwriteExistingFiles');

		if ($this->overwriteExistingFiles === '1') {
			GeneralUtility::deprecationLog('overwriteExitingFiles = 1 is deprecated. Use overwriteExitingFiles = "replace". Support for old behavior will be removed in TYPO3 CMS 8.');
			$this->overwriteExistingFiles = 'replace';
		}

		try {
			if ($combinedIdentifier) {
				/** @var $fileFactory ResourceFactory */
				$fileFactory = GeneralUtility::makeInstance(ResourceFactory::class);
				$storage = $fileFactory->getStorageObjectFromCombinedIdentifier($combinedIdentifier);
				$identifier = substr($combinedIdentifier, strpos($combinedIdentifier, ':') + 1);
				if (!$storage->hasFolder($identifier)) {
					$identifier = $storage->getFolderIdentifierFromFileIdentifier($identifier);
				}

				$this->folderObject = $fileFactory->getFolderObjectFromCombinedIdentifier($storage->getUid() . ':' . $identifier);
				// Disallow access to fallback storage 0
				if ($storage->getUid() === 0) {
					throw new Exception\InsufficientFolderAccessPermissionsException('You are not allowed to access files outside your storages', 1434539815);
				}
				// Disallow the rendering of the processing folder (e.g. could be called manually)
				if ($this->folderObject && $storage->isProcessingFolder($this->folderObject)) {
					$this->folderObject = $storage->getRootLevelFolder();
				}
			} else {
				// Take the first object of the first storage
				$fileStorages = $this->getBackendUser()->getFileStorages();
				$fileStorage = reset($fileStorages);
				if ($fileStorage) {
					$this->folderObject = $fileStorage->getRootLevelFolder();
				} else {
					throw new \RuntimeException('Could not find any folder to be displayed.', 1349276894);
				}
			}

			if ($this->folderObject && !$this->folderObject->getStorage()->isWithinFileMountBoundaries($this->folderObject)) {
				throw new \RuntimeException('Folder not accessible.', 1430409089);
			}
		} catch (Exception\InsufficientFolderAccessPermissionsException $permissionException) {
			$this->folderObject = NULL;
			$this->errorMessage = GeneralUtility::makeInstance(FlashMessage::class,
				sprintf(
					$this->getLanguageService()->getLL('missingFolderPermissionsMessage', TRUE),
					htmlspecialchars($this->id)
				),
				$this->getLanguageService()->getLL('missingFolderPermissionsTitle', TRUE),
				FlashMessage::NOTICE
			);
		} catch (Exception $fileException) {
			// Set folder object to null and throw a message later on
			$this->folderObject = NULL;
			// Take the first object of the first storage
			$fileStorages = $this->getBackendUser()->getFileStorages();
			$fileStorage = reset($fileStorages);
			if ($fileStorage instanceof \TYPO3\CMS\Core\Resource\ResourceStorage) {
				$this->folderObject = $fileStorage->getRootLevelFolder();
				if (!$fileStorage->isWithinFileMountBoundaries($this->folderObject)) {
					$this->folderObject = NULL;
				}
			}
			$this->errorMessage = GeneralUtility::makeInstance(FlashMessage::class,
				sprintf(
					$this->getLanguageService()->getLL('folderNotFoundMessage', TRUE),
					htmlspecialchars($this->id)
				),
				$this->getLanguageService()->getLL('folderNotFoundTitle', TRUE),
				FlashMessage::NOTICE
			);
		} catch (\RuntimeException $e) {
			$this->folderObject = NULL;
			$this->errorMessage = GeneralUtility::makeInstance(FlashMessage::class,
				$e->getMessage() . ' (' . $e->getCode() . ')',
				$this->getLanguageService()->getLL('folderNotFoundTitle', TRUE),
				FlashMessage::NOTICE
			);
		}

		if ($this->folderObject && !$this->folderObject->getStorage()->checkFolderActionPermission('read', $this->folderObject)) {
			$this->folderObject = NULL;
		}

		// Configure the "menu" - which is used internally to save the values of sorting, displayThumbs etc.
		$this->menuConfig();
	}

	/**
	 * Setting the menu/session variables
	 *
	 * @return void
	 */
	public function menuConfig() {
		// MENU-ITEMS:
		// If array, then it's a selector box menu
		// If empty string it's just a variable, that will be saved.
		// Values NOT in this array will not be saved in the settings-array for the module.
		$this->MOD_MENU = array(
			'sort' => '',
			'reverse' => '',
			'displayThumbs' => '',
			'clipBoard' => '',
			'bigControlPanel' => ''
		);
		// CLEANSE SETTINGS
		$this->MOD_SETTINGS = BackendUtility::getModuleData(
			$this->MOD_MENU,
			GeneralUtility::_GP('SET'),
			$this->moduleName
		);
	}

	/**
	 * Main function, creating the listing
	 *
	 * @return void
	 */
	public function main() {
		// Initialize the template object
		$this->doc = GeneralUtility::makeInstance(DocumentTemplate::class);
		$this->doc->setModuleTemplate('EXT:filelist/Resources/Private/Templates/file_list.html');

		$pageRenderer = $this->getPageRenderer();
		$pageRenderer->loadJQuery();
		$pageRenderer->loadRequireJsModule('TYPO3/CMS/Filelist/FileListLocalisation');

		// There there was access to this file path, continue, make the list
		if ($this->folderObject) {

			// Create filelisting object
			$this->filelist = GeneralUtility::makeInstance(FileList::class);
			// Apply predefined values for hidden checkboxes
			// Set predefined value for DisplayBigControlPanel:
			$backendUser = $this->getBackendUser();
			if ($backendUser->getTSConfigVal('options.file_list.enableDisplayBigControlPanel') === 'activated') {
				$this->MOD_SETTINGS['bigControlPanel'] = TRUE;
			} elseif ($backendUser->getTSConfigVal('options.file_list.enableDisplayBigControlPanel') === 'deactivated') {
				$this->MOD_SETTINGS['bigControlPanel'] = FALSE;
			}
			// Set predefined value for DisplayThumbnails:
			if ($backendUser->getTSConfigVal('options.file_list.enableDisplayThumbnails') === 'activated') {
				$this->MOD_SETTINGS['displayThumbs'] = TRUE;
			} elseif ($backendUser->getTSConfigVal('options.file_list.enableDisplayThumbnails') === 'deactivated') {
				$this->MOD_SETTINGS['displayThumbs'] = FALSE;
			}
			// Set predefined value for Clipboard:
			if ($backendUser->getTSConfigVal('options.file_list.enableClipBoard') === 'activated') {
				$this->MOD_SETTINGS['clipBoard'] = TRUE;
			} elseif ($backendUser->getTSConfigVal('options.file_list.enableClipBoard') === 'deactivated') {
				$this->MOD_SETTINGS['clipBoard'] = FALSE;
			}
			// If user never opened the list module, set the value for displayThumbs
			if (!isset($this->MOD_SETTINGS['displayThumbs'])) {
				$this->MOD_SETTINGS['displayThumbs'] = $backendUser->uc['thumbnailsByDefault'];
			}
			$this->filelist->thumbs = $this->MOD_SETTINGS['displayThumbs'];
			// Create clipboard object and initialize that
			$this->filelist->clipObj = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Clipboard\Clipboard::class);
			$this->filelist->clipObj->fileMode = 1;
			$this->filelist->clipObj->initializeClipboard();
			$CB = GeneralUtility::_GET('CB');
			if ($this->cmd == 'setCB') {
				$CB['el'] = $this->filelist->clipObj->cleanUpCBC(array_merge(GeneralUtility::_POST('CBH'), (array)GeneralUtility::_POST('CBC')), '_FILE');
			}
			if (!$this->MOD_SETTINGS['clipBoard']) {
				$CB['setP'] = 'normal';
			}
			$this->filelist->clipObj->setCmd($CB);
			$this->filelist->clipObj->cleanCurrent();
			// Saves
			$this->filelist->clipObj->endClipboard();
			// If the "cmd" was to delete files from the list (clipboard thing), do that:
			if ($this->cmd == 'delete') {
				$items = $this->filelist->clipObj->cleanUpCBC(GeneralUtility::_POST('CBC'), '_FILE', 1);
				if (!empty($items)) {
					// Make command array:
					$FILE = array();
					foreach ($items as $v) {
						$FILE['delete'][] = array('data' => $v);
					}
					switch ($this->overwriteExistingFiles) {
						case 'replace':
						case 'changeName':
							$conflictMode = $this->overwriteExistingFiles;
							break;
						default:
							$conflictMode = 'cancel';
							break;
					}

					// Init file processing object for deleting and pass the cmd array.
					/** @var ExtendedFileUtility $fileProcessor */
					$fileProcessor = GeneralUtility::makeInstance(ExtendedFileUtility::class);
					$fileProcessor->init(array(), $GLOBALS['TYPO3_CONF_VARS']['BE']['fileExtensions']);
					$fileProcessor->setActionPermissions();
					$fileProcessor->setExistingFilesConflictMode($conflictMode);
					$fileProcessor->start($FILE);
					$fileProcessor->processData();
					$fileProcessor->pushErrorMessagesToFlashMessageQueue();
				}
			}
			if (!isset($this->MOD_SETTINGS['sort'])) {
				// Set default sorting
				$this->MOD_SETTINGS['sort'] = 'file';
				$this->MOD_SETTINGS['reverse'] = 0;
			}
			// Start up filelisting object, include settings.
			$this->pointer = MathUtility::forceIntegerInRange($this->pointer, 0, 100000);
			$this->filelist->start($this->folderObject, $this->pointer, $this->MOD_SETTINGS['sort'], $this->MOD_SETTINGS['reverse'], $this->MOD_SETTINGS['clipBoard'], $this->MOD_SETTINGS['bigControlPanel']);
			// Generate the list
			$this->filelist->generateList();
			// Set top JavaScript:
			$this->doc->JScode = $this->doc->wrapScriptTags('if (top.fsMod) top.fsMod.recentIds["file"] = "' . rawurlencode($this->id) . '";' . $this->filelist->CBfunctions());
			// This will return content necessary for the context sensitive clickmenus to work: bodytag events, JavaScript functions and DIV-layers.
			$this->doc->getContextMenuCode();
			// Setting up the buttons and markers for docheader
			list($buttons, $otherMarkers) = $this->filelist->getButtonsAndOtherMarkers($this->folderObject);
			// add the folder info to the marker array
			$otherMarkers['FOLDER_INFO'] = $this->filelist->getFolderInfo();
			$docHeaderButtons = array_merge($this->getButtons(), $buttons);

			// Include DragUploader only if we have write access
			if ($this->folderObject->getStorage()->checkUserActionPermission('add', 'File')
				&& $this->folderObject->checkActionPermission('write')
			) {
				$pageRenderer->loadRequireJsModule('TYPO3/CMS/Backend/DragUploader');
				$pageRenderer->addInlineLanguagelabelFile(
					ExtensionManagementUtility::extPath('lang') . 'locallang_core.xlf',
					'file_upload'
				);
			}

			// Build the <body> for the module
			$moduleHeadline = $this->getModuleHeadline();
			// Create output
			$pageContent = $moduleHeadline !== '' ? '<h1>' . $moduleHeadline . '</h1>' : '';

			$pageContent .= '<form action="' . htmlspecialchars($this->filelist->listURL()) . '" method="post" name="dblistForm">';
			$pageContent .= $this->filelist->HTMLcode;
			$pageContent .= '<input type="hidden" name="cmd" /></form>';

			// Making listing options:
			if ($this->filelist->HTMLcode) {
				$pageContent .= '

					<!--
						Listing options for extended view, clipboard and thumbnails
					-->
					<div class="typo3-listOptions">
				';
				// Add "display bigControlPanel" checkbox:
				if ($backendUser->getTSConfigVal('options.file_list.enableDisplayBigControlPanel') === 'selectable') {
					$pageContent .= '<div class="checkbox">' .
						'<label for="bigControlPanel">' .
							BackendUtility::getFuncCheck($this->id, 'SET[bigControlPanel]', $this->MOD_SETTINGS['bigControlPanel'], '', '', 'id="bigControlPanel"') .
							$this->getLanguageService()->getLL('bigControlPanel', TRUE) .
						'</label>' .
					'</div>';
				}
				// Add "display thumbnails" checkbox:
				if ($backendUser->getTSConfigVal('options.file_list.enableDisplayThumbnails') === 'selectable') {
					$pageContent .= '<div class="checkbox">' .
						'<label for="checkDisplayThumbs">' .
							BackendUtility::getFuncCheck($this->id, 'SET[displayThumbs]', $this->MOD_SETTINGS['displayThumbs'], '', '', 'id="checkDisplayThumbs"') .
							$this->getLanguageService()->getLL('displayThumbs', TRUE) .
						'</label>' .
					'</div>';
				}
				// Add "clipboard" checkbox:
				if ($backendUser->getTSConfigVal('options.file_list.enableClipBoard') === 'selectable') {
					$pageContent .= '<div class="checkbox">' .
						'<label for="checkClipBoard">' .
							BackendUtility::getFuncCheck($this->id, 'SET[clipBoard]', $this->MOD_SETTINGS['clipBoard'], '', '', 'id="checkClipBoard"') .
							$this->getLanguageService()->getLL('clipBoard', TRUE) .
						'</label>' .
					'</div>';
				}
				$pageContent .= '
					</div>
				';
				// Set clipboard:
				if ($this->MOD_SETTINGS['clipBoard']) {
					$pageContent .= $this->filelist->clipObj->printClipboard();
					$pageContent .= BackendUtility::cshItem('xMOD_csh_corebe', 'filelist_clipboard');
				}
			}
			$markerArray = array(
				'CSH' => $docHeaderButtons['csh'],
				'FUNC_MENU' => BackendUtility::getFuncMenu($this->id, 'SET[function]', $this->MOD_SETTINGS['function'], $this->MOD_MENU['function']),
				'CONTENT' => ($this->errorMessage ? $this->errorMessage->render() : '') . $pageContent,
				'FOLDER_IDENTIFIER' => $this->folderObject->getCombinedIdentifier(),
				'FILEDENYPATERN' => $GLOBALS['TYPO3_CONF_VARS']['BE']['fileDenyPattern'],
				'MAXFILESIZE' => GeneralUtility::getMaxUploadFileSize() * 1024,
			);
			$this->content = $this->doc->moduleBody(array(), $docHeaderButtons, array_merge($markerArray, $otherMarkers));
			// Renders the module page
			$this->content = $this->doc->render($this->getLanguageService()->getLL('files'), $this->content);
		} else {
			$content = '';
			if ($this->errorMessage) {
				$this->errorMessage->setSeverity(FlashMessage::ERROR);
				$content = $this->doc->moduleBody(array(), array_merge(array('REFRESH' => '', 'PASTE' => '', 'LEVEL_UP' => ''), $this->getButtons()), array('CSH' => '', 'TITLE' => '', 'FOLDER_INFO' => '', 'PAGE_ICON' => '', 'FUNC_MENU' => '', 'CONTENT' => $this->errorMessage->render()));
			}
			// Create output - no access (no warning though)
			$this->content = $this->doc->render($this->getLanguageService()->getLL('files'), $content);
		}
	}

	/**
	 * Get main headline based on active folder or storage for backend module
	 *
	 * Folder names are resolved to their special names like done in the tree view.
	 *
	 * @return string
	 */
	protected function getModuleHeadline() {
		$name = $this->folderObject->getName();
		if ($name === '') {
			// Show storage name on storage root
			if ($this->folderObject->getIdentifier() === '/') {
				$name = $this->folderObject->getStorage()->getName();
			}
		} else {
			$name = key(ListUtility::resolveSpecialFolderNames(
				array($name => $this->folderObject)
			));
		}
		return $name;
	}

	/**
	 * Outputting the accumulated content to screen
	 *
	 * @return void
	 */
	public function printContent() {
		echo $this->content;
	}

	/**
	 * Create the panel of buttons for submitting the form or otherwise perform operations.
	 *
	 * @return array All available buttons as an assoc. array
	 */
	public function getButtons() {
		/** @var IconFactory $iconFactory */
		$iconFactory = GeneralUtility::makeInstance(IconFactory::class);

		$buttons = array(
			'csh' => '',
			'shortcut' => '',
			'upload' => '',
			'new' => ''
		);
		// Add shortcut
		if ($this->getBackendUser()->mayMakeShortcut()) {
			$buttons['shortcut'] = $this->doc->makeShortcutIcon('pointer,id,target,table', implode(',', array_keys($this->MOD_MENU)), $this->moduleName);
		}
		// FileList Module CSH:
		$buttons['csh'] = BackendUtility::cshItem('xMOD_csh_corebe', 'filelist_module');
		// Upload button (only if upload to this directory is allowed)
		if ($this->folderObject && $this->folderObject->getStorage()->checkUserActionPermission('add', 'File') && $this->folderObject->checkActionPermission('write')) {
			$buttons['upload'] = '<a href="' . htmlspecialchars(
				BackendUtility::getModuleUrl(
					'file_upload',
					array(
						'target' => $this->folderObject->getCombinedIdentifier(),
						'returnUrl' => $this->filelist->listURL(),
					)
				)) . '" id="button-upload" title="' . $this->getLanguageService()->makeEntities($this->getLanguageService()->sL('LLL:EXT:lang/locallang_core.xlf:cm.upload', TRUE)) . '">' . IconUtility::getSpriteIcon('actions-edit-upload') . '</a>';
		}
		// New folder button
		if ($this->folderObject && $this->folderObject->checkActionPermission('write')
			&& ($this->folderObject->getStorage()->checkUserActionPermission('add', 'File') || $this->folderObject->checkActionPermission('add'))
		) {
			$buttons['new'] = '<a href="' . htmlspecialchars(
				BackendUtility::getModuleUrl(
					'file_newfolder',
					array(
						'target' => $this->folderObject->getCombinedIdentifier(),
						'returnUrl' => $this->filelist->listURL(),
					)
				)) . '" title="' . $this->getLanguageService()->makeEntities($this->getLanguageService()->sL('LLL:EXT:lang/locallang_core.xlf:cm.new', TRUE)) . '">' . $iconFactory->getIcon('actions-document-new', Icon::SIZE_SMALL) . '</a>';
		}
		return $buttons;
	}

	/**
	 * Returns an instance of LanguageService
	 *
	 * @return \TYPO3\CMS\Lang\LanguageService
	 */
	protected function getLanguageService() {
		return $GLOBALS['LANG'];
	}

	/**
	 * Returns the current BE user.
	 *
	 * @return \TYPO3\CMS\Core\Authentication\BackendUserAuthentication
	 */
	protected function getBackendUser() {
		return $GLOBALS['BE_USER'];
	}

	/**
	 * @return PageRenderer
	 */
	protected function getPageRenderer() {
		return GeneralUtility::makeInstance(PageRenderer::class);
	}

}
