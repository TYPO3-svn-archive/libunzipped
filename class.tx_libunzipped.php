<?php
/***************************************************************
*  Copyright notice
*  
*  (c) 2003-2004 Kasper Skårhøj (kasper@typo3.com)
*  All rights reserved
*
*  This script is part of the Typo3 project. The Typo3 project is 
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
* 
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
* 
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/
/** 
 * Class for unzipping (and re-zipping) ZIP files and store them into database.
 *
 * @author	 Kasper Skårhøj <kasper@typo3.com>
 */
/**
 * [CLASS/FUNCTION INDEX of SCRIPT]
 *
 *
 *
 *   65: class tx_libunzipped 
 *
 *              SECTION: Initialize the ZIP archive (possibly unzip it and store in database)
 *  107:     function init($file,$extIdStr='')	
 *  145:     function setExternalID($string)	
 *  154:     function clearCachedContent()	
 *  165:     function extractZippedDocumentsAndCacheIt()	
 *  208:     function storeFilesInDB($path)	
 *  256:     function getFileListFromDB()	
 *  285:     function removeDir($tempDir)	
 *
 *              SECTION: Writing ZIP file from DB archive
 *  340:     function compileZipFile($filepath)	
 *  409:     function compileZipFile_writeFilePath($tempDir,$filepath,$content)	
 *
 *              SECTION: Access the zip content
 *  454:     function getFileFromArchive($filepath)	
 *  480:     function getFileFromXML($filepath)	
 *  491:     function putFileToArchive($filepath,$content)	
 *
 * TOTAL FUNCTIONS: 12
 * (This index is automatically created/updated by the extension "extdeveval")
 *
 */ 


/**
 * Class for unzipping (and re-zipping) ZIP files and store them into database.
 * 
 * @author	 Kasper Skårhøj <kasper@typo3.com>
 */
class tx_libunzipped {

		// EXTERNAL static values:

		// unzipAppCmd contains the commandline for the unzipping tool. Will be overridden if a different commandline
		//	was provided when this extension was installed

	var $unzipAppCmd = 'unzip -qq ###ARCHIVENAME### -d ###DIRECTORY###';	// Unzip Application (don't set to blank!) ** MODIFIED RL, 15.08.03
	var $unzipAppCmd_list = 'unzip -l ###ARCHIVENAME###';	// Unzip Application for listing content including the total size as the first integer in the last line of trimmed output.
	var $zipAppCmd = 'zip -r ###ARCHIVENAME### ./*';

		// Example for WinRAR:
		//	var $unzipAppCmd ='c:\Programme\WinRAR\winrar.exe x -afzip -ibck -inul -o+ ###ARCHIVENAME### ###DIRECTORY###';

	var $compressedStorage = FALSE;		// Boolean. If set, gzcompress will be used to compress the files before insertion in the database!
	var $maxUnzippedSize = 10000;		// Before unzipping archives the script will check that the reported total filesize inside does not exceed this amount of kilobytes. Otherwise someone could pack 100MB empty space into a 100 bytes zip archive - and sabotage the server...

		// Internal, dynamic:
	var $file = '';			// Reference to file, absolute path.
	var $fileHash = '';		// Is set to a md5-hash string based on the filename + mtime.
	var $mtime = 0;			// Is set to the mtime integer of the file.
	var $ext_ID = '';		// Is set to an id string identifying this file storage. Default is to set it to an integer hash of the filename.





	/*********************************
	 *
	 * Initialize the ZIP archive (possibly unzip it and store in database)
	 *
	 *********************************/

	/**
	 * Init object with abs path to ZIP file.
	 * Will make sure that the ZIP file is read and stored in database (if that is not the case already)
	 * Returns '' on success, otherwise an error string.
	 * 
	 * @param	string		Absolute path to the file which is to be libunzipped/stored in DB (remember, no file inside can be larger than what can be stored in a BLOB. You may need to change the MySQL configuration file, '/etc/mysql/my.cnf', setting a higher value than '2' for 'max_allowed_packet' )
	 * @param	string		ID string to use (alternative to the internally calculated)
	 * @return	array		Returns the filelist in ZIP file, otherwise an error string.
	 */
	function init($file,$extIdStr='')	{

			// Looking for configuration:
		$staticConf = unserialize ($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['libunzipped']);
		if ($staticConf['unzipAppCmd']) {
			$this->unzipAppCmd = $staticConf['unzipAppCmd'];
		}
			// Checking the file:
		if (is_file($file))	{
			if (t3lib_div::isAbsPath($file))	{
				$this->file = $file;
				$this->setExternalID($extIdStr ? $extIdStr : $this->file);	// Default value...

						// Make hash string:
				$this->mtime = filemtime($this->file);
				$this->fileHash = md5($this->file.'|'.$this->mtime);

					// Get file list from DB:
				if (!count($this->getFileListFromDB()))	{
					$this->clearCachedContent();
					$cc = $this->extractZippedDocumentsAndCacheIt();
					if (!$cc || !t3lib_div::testInt($cc))	{
						return 'No files found in ZIP file - or some other error: '.$cc;
					}
				}

				return $this->getFileListFromDB();
			} else return 'Not absolute file reference';
		} else return 'Not a file.';
	}

	/**
	 * Setting external id ($this->ext_ID)
	 * This is useful if some plugin wants to identify a document not by its filename (which may have been changed) but by its relationship to the plugin.
	 * 
	 * @param	string		String to be hashed. Eg. filename
	 * @return	void		
	 */
	function setExternalID($string)	{
		$this->ext_ID = hexdec(substr(md5($string),0,7));
	}

	/**
	 * Clearing cached content for $this->ext_ID
	 * 
	 * @return	void		
	 */
	function clearCachedContent()	{
		$GLOBALS['TYPO3_DB']->exec_DELETEquery('tx_libunzipped_filestorage', 'rel_id='.intval($this->ext_ID));
	}

	/**
	 * This takes the ZIP file, unzips it, reads all documents, store them in database for next retrieval.
	 * The file is libunzipped in PATH_site.'typo3temp/' + a randomly named folder.
	 * 
	 * @return	void		
	 * @access private
	 */
	function extractZippedDocumentsAndCacheIt()	{

			// Checking file existence first
		if (is_file($this->file))	{

				// Create temporary directory:
			$tempDir = PATH_site.'typo3temp/'.md5(microtime());
			mkdir($tempDir, 0777);
			if (is_dir($tempDir))	{
				$tempDir.='/';

					// Checking the total size of content:
				$cmd = $this->unzipAppCmd_list;
				$cmd = str_replace('###ARCHIVENAME###', $this->file, $cmd);
				exec($cmd,$dat);
				$totalSize = intval(end($dat));		// First integer in last line...

					// Checking max unzipped size...
				if ($totalSize/1024 < $this->maxUnzippedSize)	{

						// Unzip the files inside:
					$cmd = $this->unzipAppCmd;
					$cmd = str_replace ('###ARCHIVENAME###', $this->file, $cmd);
					$cmd = str_replace ('###DIRECTORY###', $tempDir, $cmd);
					exec($cmd, $dat);

					$cc = $this->storeFilesInDB($tempDir);
					$this->removeDir($tempDir);
					return $cc;

				} else return 'Size of content was greater than '.$this->maxUnzippedSize.' Kbytes and could not safely be unzipped due to security restrictions';
			} else return 'No dir: '.$tempDir;
		} else return 'No file: '.$this->file;
	}

	/**
	 * Traverses the directory $path and stores all files in the database hash table (one file per record)
	 * 
	 * @param	string		The path to the temporary folder created in typo3temp/
	 * @return	integer		The number of files traversed.
	 * @access private
	 * @see extractZippedDocumentsAndCacheIt()
	 */
	function storeFilesInDB($path)	{

			// Initialize:
		$allFiles = array();
		$cc = 0;
		$fileArr = t3lib_div::getAllFilesAndFoldersInPath(array(),$path);

		foreach($fileArr as $filePath)	{
			if (is_file($filePath))	{

					// Getting file information:
				$fI = pathinfo($filePath);
				$info = @getimagesize($filePath);

					// Creating array to insert into database:
				$fArray = array(
					'filemtime' => filemtime($filePath),
					'filesize' => filesize($filePath),
					'filetype' => strtolower($fI['extension']),
					'filename' => $fI['basename'],
					'filepath' => substr($filePath,strlen($path)),
					'info' => serialize($info),
					'compressed' => ($this->compressedStorage ? 1 : 0)
				);
				$allFiles[] = $fArray;

					// Adding file content, compressed or not:
				$fArray['content'] = t3lib_div::getUrl($filePath);
				if ($this->compressedStorage)	$fArray['content'] = gzcompress($fArray['content']);

					// Adding IDs / hashes:
				$fArray['rel_id'] = $this->ext_ID;
				$fArray['hash'] = $this->fileHash;

					// Insert into database
				$GLOBALS['TYPO3_DB']->exec_INSERTquery('tx_libunzipped_filestorage', $fArray);

				$cc++;
			}
		}
		return $cc;
	}

	/**
	 * Returns an array with the filelist of the ZIP archive initialized to the function.
	 * 
	 * @return	array		Array of rows with file information for each entry
	 */
	function getFileListFromDB()	{

			// Execute the SELECT of the filelists:
		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
					'uid,rel_id,hash,filemtime,filesize,filetype,filename,filepath,compressed,info',
					'tx_libunzipped_filestorage',
					'rel_id='.intval($this->ext_ID).'
						AND hash="'.$GLOBALS['TYPO3_DB']->quoteStr($this->fileHash,'tx_libunzipped_filestorage').'"'
				);

			// Traverse result rows
		$output = array();
		while ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res))	{
			$output[] = $row;
		}

			// Return resulting array:
		return $output;
	}

	/**
	 * Removes directory with all files from the path $tempDir.
	 * $tempDir must be a subfolder to typo3temp/
	 * 
	 * @param	string		Absolute path to directory to remove
	 * @return	void		
	 * @access private
	 * @see extractZippedDocumentsAndCacheIt()
	 */
	function removeDir($tempDir)	{

			// Checking that input directory was within
		$testDir = PATH_site.'typo3temp/';
		if (t3lib_div::validPathStr($tempDir) && !t3lib_div::isFirstPartOfStr($tempDir,$testDir))	die($tempDir.' was not within '.$testDir);

			// Go through dirs:
		$dirs = t3lib_div::get_dirs($tempDir);
		if (is_array($dirs))	{
			foreach($dirs as $subdirs)	{
				if ($subdirs)	{
					$this->removeDir($tempDir.$subdirs.'/');
				}
			}
		}

			// Then files in this dir:
		$fileArr = t3lib_div::getFilesInDir($tempDir,'',1);
		if (is_array($fileArr))	{
			foreach($fileArr as $file)	{
				if (!t3lib_div::isFirstPartOfStr($file,$testDir))	die($file.' was not within '.$testDir);	// PARAnoid...
				unlink($file);
			}
		}

			// Remove this dir:
		rmdir($tempDir);
	}














	/*********************************
	 *
	 * Writing ZIP file from DB archive
	 *
	 *********************************/

	/**
	 * Creates a ZIPped file from current initialized ZIP archive, reading the file content from database and writing into the ZIP file.
	 * NOTICE: Does NOT work with safe_mode because we cannot compress files into a zip archive with an absolute path!!!
	 * 
	 * @param	string		Absolute path to the filename into which to create the ZIP archive.
	 * @return	array		The output from the ZIP-compressed, otherwise an error string.
	 */
	function compileZipFile($filepath)	{

		if (!ini_get("safe_mode"))	{
			$outputFile = t3lib_div::getFileAbsFileName($filepath);

			if ($outputFile)	{

					// Create temporary directory:
				$tempDir = PATH_site.'typo3temp/'.md5(microtime());
				mkdir($tempDir, 0777);
				if (is_dir($tempDir))	{
					$error = '';
					$tempDir.='/';

						// Get filelist:
					$fileList = $this->getFileListFromDB();

						// Write files:
					foreach($fileList as $infoRec)	{
						$file_rec = $this->getFileFromArchive($infoRec['filepath']);
						if (!$this->compileZipFile_writeFilePath($tempDir,$file_rec['filepath'], $file_rec['content']))	{
								// Return error:
							$error = 'File "'.$file_rec['filepath'].'" was not written correctly to disk!';
							break;
						}
					}

					if (!$error)	{

							// Change to the directory to compress:
						chdir($tempDir);
						$res = '';
						exec('pwd',$res);
						if (!strcmp(getcwd().'/', $tempDir) && !strcmp($res[0].'/', $tempDir))	{	// ... check it VERY good...

								// Create temporary filename to create archive in:
							$tempFileBase = PATH_site.'typo3temp/'.md5(microtime()).'.zip';

								// Call "zip":
							$res = '';
							$execCmd = str_replace('###ARCHIVENAME###', $tempFileBase, $this->zipAppCmd);	// This DOES NOT work in safe_mode since "*" triggers PHP to not execute the command!!! But it would work with an absolute path to the file though - but then the ZIP archive will become corrupted.
							exec($execCmd, $res);

								// Check result:
							if (@is_file($tempFileBase))	{
								rename($tempFileBase,$outputFile);

									// Setting file system mode of file:
								if (@is_file($outputFile) && TYPO3_OS!='WIN')	{
									@chmod ($outputFile, octdec($GLOBALS['TYPO3_CONF_VARS']['BE']['fileCreateMask']));
								}
							} else $error = 'The ZIP file was NOT written to "'.$tempFileBase.'"';
						} else $error = 'Could not change directory!! '.getcwd();
					}

						// Delete directory again:
					$this->removeDir($tempDir);

						// Return result:
					return $error ? $error : $res;
				} else return 'Temporary directory, "'.$tempDir.'", could not be created!';
			} else return 'Output filename was not acceptable: "'.$filepath.'"';
		} else return 'PHP "safe_mode" is enabled - cannot create the ZIP archive because of this!';
	}

	/**
	 * Write a file to disk, possibly creating the directory structure in the path if it didn't exist already
	 * 
	 * @param	string		Main directory, absolute path
	 * @param	string		Filepath relative to main directory
	 * @param	string		Content string to write
	 * @return	boolean		True on success, otherwise false
	 * @access private
	 */
	function compileZipFile_writeFilePath($tempDir,$filepath,$content)	{

			// Create directories:
		$allDirs = t3lib_div::trimExplode('/',dirname($filepath),1);
		$root = '';
		foreach($allDirs as $dirParts)	{
			$root.=$dirParts.'/';
			if (!is_dir($tempDir.$root))	{
				@mkdir(ereg_replace('\/$','',$tempDir.$root), 0777);
				if (!@is_dir($tempDir.$root))	{
					die( 'Error: The directory "'.$extDirPath.$root.'" could not be created...' );
				}
			}
		}

			// Create file
		t3lib_div::writeFile($tempDir.$filepath, $content);

		if (@is_file($tempDir.$filepath))	{
			return TRUE;
		} else return FALSE;
	}











	/*********************************
	 *
	 * Access the zip content
	 *
	 *********************************/

	/**
	 * Returns a file with the relative (to the ZIP-file) path $filepath from the currently cached ZIP-file (read from database)
	 * 
	 * @param	string		Filename inside the ZIP archive initialized to the object.
	 * @return	array		Database row containing the content. Content variable will be uncompressed if that option was set.
	 */
	function getFileFromArchive($filepath)	{

			// Execute the SELECT of a single file within the initialized ZIP archive:
		$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
					'*',
					'tx_libunzipped_filestorage',
					'rel_id='.intval($this->ext_ID).'
						AND hash="'.$GLOBALS['TYPO3_DB']->quoteStr($this->fileHash,'tx_libunzipped_filestorage').'"
						AND filepath="'.$GLOBALS['TYPO3_DB']->quoteStr($filepath,'tx_libunzipped_filestorage').'"'
				);

		if ($row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res))	{
			if ($row['compressed'])	$row['content'] = gzuncompress($row['content']);
			return $row;
		}
	}

	/**
	 * Alias function for ->getFileFromArchive()
	 * Do NOT use this function, may be removed some day. It has a non-sense name and thats why it's changed now.
	 * 
	 * @param	string		see getFileFromArchive()
	 * @return	array		see getFileFromArchive()
	 * @see getFileFromArchive()
	 * @depreciated
	 */
	function getFileFromXML($filepath)	{
		return $this->getFileFromArchive($filepath);
	}

	/**
	 * Stores filecontent for a file with the relative (to the ZIP-file) path $filepath from the currently cached ZIP-file (read from database)
	 * 
	 * @param	string		Filename inside the ZIP archive initialized to the object.
	 * @param	string		Content string
	 * @return	boolean		False on success, otherwise error string.
	 */
	function putFileToArchive($filepath,$content)	{

			// First, get original record:
		$record = $this->getFileFromArchive($filepath);

		if (is_array($record))	{

				// Set content:
			$fArray = array(
				'filesize' => strlen($content),
				'compressed' => ($this->compressedStorage ? 1 : 0),
				'content' => $content
			);
			if ($this->compressedStorage)	$fArray['content'] = gzcompress($fArray['content']);

				// Execute the UPDATE of a single file within the initialized ZIP archive:
			$res = $GLOBALS['TYPO3_DB']->exec_UPDATEquery(
					'tx_libunzipped_filestorage',
					'rel_id='.intval($this->ext_ID).'
						AND hash="'.$GLOBALS['TYPO3_DB']->quoteStr($this->fileHash,'tx_libunzipped_filestorage').'"
						AND filepath="'.$GLOBALS['TYPO3_DB']->quoteStr($filepath,'tx_libunzipped_filestorage').'"',
					$fArray
				);

			if (!$GLOBALS['TYPO3_DB']->sql_error())	{
				return TRUE;
			} else return 'ERROR: '.$GLOBALS['TYPO3_DB']->sql_error();
		} else return 'ERROR: Could not find file referred to.';
	}
}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/libunzipped/class.tx_libunzipped.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/libunzipped/class.tx_libunzipped.php']);
}
?>
