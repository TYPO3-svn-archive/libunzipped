# TYPO3 Extension Manager dump 1.0
#
# Host: TYPO3_host    Database: t3_devdb
#--------------------------------------------------------

#
# Table structure for table 'tx_oodocs_filestorage'
#
CREATE TABLE tx_libunzipped_filestorage (
  uid int(11) unsigned DEFAULT '0' NOT NULL auto_increment,
  rel_id int(11) unsigned DEFAULT '0' NOT NULL,
  hash varchar(32) DEFAULT '0' NOT NULL,
  filemtime int(11) unsigned DEFAULT '0' NOT NULL,
  filesize int(11) unsigned DEFAULT '0' NOT NULL,
  filetype varchar(10) DEFAULT '0' NOT NULL,
  filename tinytext NOT NULL,
  filepath tinytext NOT NULL,
  compressed tinyint(3) DEFAULT '0' NOT NULL,
  info blob NOT NULL,
  content mediumblob NOT NULL,
  PRIMARY KEY (uid),
  KEY getfile (rel_id,hash)
);

