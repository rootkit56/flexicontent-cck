<?php
/**
 * @version 1.5 stable $Id: fileselement.php 1699 2013-07-30 04:29:37Z ggppdk $
 * @package Joomla
 * @subpackage FLEXIcontent
 * @copyright (C) 2009 Emmanuel Danan - www.vistamedia.fr
 * @license GNU/GPL v2
 * 
 * FLEXIcontent is a derivative work of the excellent QuickFAQ component
 * @copyright (C) 2008 Christoph Lukes
 * see www.schlu.net for more information
 *
 * FLEXIcontent is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

// no direct access
defined('_JEXEC') or die('Restricted access');

jimport('legacy.model.legacy');
jimport('joomla.filesystem.file');
use Joomla\String\StringHelper;

if ( JFactory::getApplication()->isSite() )
{
	// needed for frontend
	JFactory::getLanguage()->load('com_flexicontent', JPATH_ADMINISTRATOR, 'en-GB', true);
	JFactory::getLanguage()->load('com_flexicontent', JPATH_ADMINISTRATOR, null, true);
}

/**
 * FLEXIcontent Component Fileselement Model
 *
 * @package Joomla
 * @subpackage FLEXIcontent
 * @since		1.0
 */
class FlexicontentModelFileselement extends JModelLegacy
{
	/**
	 * file data
	 *
	 * @var object
	 */
	var $_data = null;

	/**
	 * file total
	 *
	 * @var integer
	 */
	var $_total = null;

	/**
	 * Pagination object
	 *
	 * @var object
	 */
	var $_pagination = null;

	/**
	 * file id
	 *
	 * @var int
	 */
	var $_id = null;

	/**
	 * Constructor
	 *
	 * @since 1.0
	 */
	 
	/**
	 * uploaders
	 *
	 * @var object
	 */
	var $_users = null;

	function __construct()
	{
		parent::__construct();
		
		$app    = JFactory::getApplication();
		$jinput = $app->input;
		$option = $jinput->get('option', '', 'cmd');
		$view   = $jinput->get('view', '', 'cmd');
		$fcform = $jinput->get('fcform', 0, 'int');
		$p      = $option.'.'.$view.'.';
		
		$this->fieldid = $jinput->get('field', null, 'int');  // not yet used for filemanager view, only for fileselement views
		$this->viewid  = $view.$this->fieldid;
		
		
		
		// ****************************************
		// Ordering: filter_order, filter_order_Dir
		// ****************************************
		
		$default_order     = 'f.uploaded'; //'f.id';
		$default_order_dir = 'DESC';
		
		$filter_order      = $fcform ? $jinput->get('filter_order',     $default_order,      'cmd')  :  $app->getUserStateFromRequest( $p.'filter_order',     'filter_order',     $default_order,      'cmd' );
		$filter_order_Dir  = $fcform ? $jinput->get('filter_order_Dir', $default_order_dir, 'word')  :  $app->getUserStateFromRequest( $p.'filter_order_Dir', 'filter_order_Dir', $default_order_dir, 'word' );
		
		if (!$filter_order)     $filter_order     = $default_order;
		if (!$filter_order_Dir) $filter_order_Dir = $default_order_dir;
		
		$this->setState('filter_order', $filter_order);
		$this->setState('filter_order_Dir', $filter_order_Dir);
		
		$app->setUserState($p.'filter_order', $filter_order);
		$app->setUserState($p.'filter_order_Dir', $filter_order_Dir);
		
		
		
		// **************
		// view's Filters
		// **************
		
		
		// Text search
		$scope  = $fcform ? $jinput->get('scope',  1,  'int')     :  $app->getUserStateFromRequest( $p.'scope',   'scope',   1,   'int' );
		$search = $fcform ? $jinput->get('search', '', 'string')  :  $app->getUserStateFromRequest( $p.'search',  'search',  '',  'string' );
		
		$this->setState('scope', $scope);
		$this->setState('search', $search);
		
		$app->setUserState($p.'scope', $scope);
		$app->setUserState($p.'search', $search);
		
		
		
		// *****************************
		// Pagination: limit, limitstart
		// *****************************
		
		$limit      = $fcform ? $jinput->get('limit', $app->getCfg('list_limit'), 'int')  :  $app->getUserStateFromRequest( $p.'limit', 'limit', $app->getCfg('list_limit'), 'int');
		$limitstart = $fcform ? $jinput->get('limitstart',                     0, 'int')  :  $app->getUserStateFromRequest( $p.'limitstart', 'limitstart', 0, 'int' );
		
		// In case limit has been changed, adjust limitstart accordingly
		$limitstart = ( $limit != 0 ? (floor($limitstart / $limit) * $limit) : 0 );
		$jinput->set( 'limitstart',	$limitstart );
		
		$this->setState('limit', $limit);
		$this->setState('limitstart', $limitstart);
		
		$app->setUserState($p.'limit', $limit);
		$app->setUserState($p.'limitstart', $limitstart);
		
		
		// For some model function that use single id
		$array = $jinput->get('cid', array(0), 'array');
		$this->setId((int)$array[0]);
	}
	
	
	/**
	 * Method to set the files identifier
	 *
	 * @access	public
	 * @param	int file identifier
	 */
	function setId($id)
	{
		// Set id and wipe data
		$this->_id	 = $id;
		$this->_data = null;
	}


	/**
	 * Method to get files data
	 *
	 * @access public
	 * @return array
	 */
	function getData()
	{
		// Get items using files VIA (single property) field types (that store file ids) by using main query
		$s_assigned_via_main = false;
		
		// Files usage my single / multi property Fields.
		// (a) Single property field types: store file ids
		// (b) Multi property field types: store file id or filename via some property name
		$s_assigned_fields = array('file', 'minigallery');
		$m_assigned_fields = array('image');
		
		$m_assigned_props = array('image'=>'originalname');
		$m_assigned_vals = array('image'=>'filename');
		
		// Lets load the files if it doesn't already exist
		if (empty($this->_data))
		{
			$query = $s_assigned_via_main  ?  $this->_buildQuery($s_assigned_fields)  :  $this->_buildQuery();
			
			$this->_data = $this->_getList($query, $this->getState('limitstart'), $this->getState('limit'));
			$this->_db->setQuery("SELECT FOUND_ROWS()");
			$this->_total = $this->_db->loadResult();
			
			$this->_data = flexicontent_images::BuildIcons($this->_data);
			
			// Single property fields, get file usage (# assignments), if not already done by main query
			if ( !$s_assigned_via_main && $s_assigned_fields)
			{
				foreach ($s_assigned_fields as $field_type)
				{
					$this->countFieldRelationsSingleProp( $this->_data, $field_type );
				}
			}
			// Multi property fields, get file usage (# assignments)
			if ($m_assigned_fields)
			{
				foreach ($m_assigned_fields as $field_type)
				{
					$field_prop = $m_assigned_props[$field_type];
					$value_prop = $m_assigned_vals[$field_type];
					$this->countFieldRelationsMultiProp($this->_data, $value_prop, $field_prop, $field_type='image');
				}
			}
		}
		
		return $this->_data;
	}
	
	
	/**
	 * Method to get the total nr of the files
	 *
	 * @access public
	 * @return integer
	 */
	function getTotal()
	{
		// Lets load the files if it doesn't already exist
		if (empty($this->_total))
		{
			$query = $this->_buildQuery();
			$this->_total = $this->_getListCount($query);
		}

		return $this->_total;
	}
	
	
	/**
	 * Method to get a pagination object for the files
	 *
	 * @access public
	 * @return integer
	 */
	function getPagination()
	{
		// Lets load the files if it doesn't already exist
		if (empty($this->_pagination))
		{
			jimport('cms.pagination.pagination');
			$this->_pagination = new JPagination( $this->getTotal(), $this->getState('limitstart'), $this->getState('limit') );
		}

		return $this->_pagination;
	}
	
	
	/**
	 * Method to get files having the given extensions from a given folder
	 *
	 * @access private
	 * @return integer
	 * @since 1.0
	 */
	function getFilesFromPath($itemid, $fieldid, $append_item=1, $append_field=0, $folder_param_name='dir', $exts='bmp,csv,doc,docx,gif,ico,jpg,jpeg,odg,odp,ods,odt,pdf,png,ppt,pptx,swf,txt,xcf,xls,xlsx,zip,ics')
	{
		$app    = JFactory::getApplication();
		$jinput = $app->input;
		$option = $jinput->get('option', '', 'cmd');

		$imageexts = array('jpg','gif','png','bmp','jpeg');  // Common image extensions
		$gallery_folder = $this->getFieldFolderPath($itemid, $fieldid, $append_item, $append_field, $folder_param_name);
		//echo $gallery_folder ."<br />";
		
		// Create folder for current language
		if (!is_dir($gallery_folder)) {
			mkdir($gallery_folder, $mode = 0755, $recursive=true);
		}
		
		
		// Get file list according to filtering
		$exts = preg_replace("/[\s]*,[\s]*/", '|', $exts);
		$it = new RegexIterator(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($gallery_folder)), '#(.*\.)('.$exts.')#i');
		$it->rewind();
		
		// Get file information
		$rows = array();
		$i = 1;
		while($it->valid())
		{
			if ($it->isDot()) {
				$it->next();
				continue;
			}
			$filesubpath = $it->getSubPathName();  // filename including the folder subpath
			$filepath = $it->key();
			$pinfo = pathinfo($filepath);
			$row = new stdClass();
			$row->ext = $pinfo['extension'];
			// Convert directory separators inside the subpath
			$row->filename = str_replace('\\', '/', $filesubpath);  //$pinfo['filename'].".".$pinfo['extension'];
			// Try to create a UTF8 filename
			$row->filename_original = iconv(mb_detect_encoding($row->filename, mb_detect_order(), true), "UTF-8", $row->filename);
			$row->filename_original = $row->filename_original ? $row->filename_original : $row->filename;
			$row->size = sprintf("%.0f KB", (filesize($filepath) / 1024) );
			$row->altname = $pinfo['filename'];
			$row->uploader = '-';
			$row->uploaded = date("F d Y H:i:s.", filectime($filepath) );
			$row->id = $i;
			
			if ( in_array(strtolower($row->ext), $imageexts)) {
				$row->icon = JURI::root()."components/com_flexicontent/assets/images/mime-icon-16/image.png";
			} elseif (file_exists(JPATH_SITE."/components/com_flexicontent/assets/images/mime-icon-16/".$row->ext.".png")) {
				$row->icon = JURI::root()."components/com_flexicontent/assets/images/mime-icon-16/".$row->ext.".png";
			} else {
				$row->icon = JURI::root()."components/com_flexicontent/assets/images/mime-icon-16/unknown.png";
			}
			$rows[] = $row;
			
			$i++;
			$it->next();
		}
		
		return $rows;
	}


	/**
	 * Method to get the folder path defined in a field
	 *
	 * @access	public
	 */
	function getFieldFolderPath($itemid, $fieldid, $append_item=1, $append_field=0, $folder_param_name='dir')
	{
		$field = $this->getField();
		if (!$field)
		{
			die('Field for id:' . $this->fieldid . ' was not found');
		}
		$gallery_path = JPATH_SITE . DS . $field->parameters->get($folder_param_name, 'images/stories/flexicontent') . '/';
		if ($append_item) $gallery_path .= 'item_' . $itemid;
		if ($append_field) $gallery_path .= '_field_' . $fieldid;
		$gallery_path .= '/original';
		return str_replace('\\','/', $gallery_path);
	}


	/**
	 * Method to get field data
	 *
	 * @access public
	 * @return object
	 */
	function getField($fieldid=0)
	{
		static $fields = array();
		
		// Return cached field data
		$fieldid = (int) ($fieldid ?: $this->fieldid);
		if (isset($fields[$fieldid]))
		{
			return $fields[$fieldid];
		}

		// Get field data from DB
		$fields[$fieldid] = false;
		if ($fieldid)
		{
			$this->_db->setQuery('SELECT * FROM #__flexicontent_fields WHERE id= ' . $fieldid);
			$fields[$fieldid] = $this->_db->loadObject();
		}

		// Parse field parameters and return field
		if (!empty($fields[$fieldid]))
		{
			$fields[$fieldid]->parameters = new JRegistry($fields[$fieldid]->attribs);
		}
		return $fields[$fieldid];
	}


	/**
	 * Method to build the query for the files
	 *
	 * @access private
	 * @return integer
	 * @since 1.0
	 */
	function _buildQuery( $assigned_fields=array(), $item_id=0, $ids_only=false )
	{
		$app    = JFactory::getApplication();
		$jinput = $app->input;
		$option = $jinput->get('option', '', 'cmd');
		
		// Get the WHERE, HAVING and ORDER BY clauses for the query
		$join    = $this->_buildContentJoin();
		$where   = $this->_buildContentWhere();
		$orderby = !$ids_only ? $this->_buildContentOrderBy() : '';
		$having  = ''; //$this->_buildContentHaving();
		
		$filter_item = $item_id  ?  $item_id  :  $app->getUserStateFromRequest( $option.'.'.$this->viewid.'.item_id',   'item_id',   '',   'int' );
		if ($filter_item)
		{
			$join	.= ' JOIN #__flexicontent_fields_item_relations AS rel ON rel.item_id = '. $filter_item .' AND f.id = rel.value ';
			$join	.= ' JOIN #__flexicontent_fields AS fi ON fi.id = rel.field_id AND fi.field_type = ' . $this->_db->Quote('file');
		}
		
		if ( !$ids_only )
		{
			$join .= ' LEFT JOIN #__viewlevels AS level ON level.id=f.access';
		}
		
		if ( $ids_only ) {
			$columns[] = 'f.id';
		} else {
			$columns[] = 'SQL_CALC_FOUND_ROWS f.*, u.name AS uploader,'
				.' CASE WHEN f.filename_original<>"" THEN f.filename_original ELSE f.filename END AS filename_displayed ';
			if ( !empty($assigned_fields) )
			{
				foreach ($assigned_fields as $field_type)
				{
					// Field relation sub query for counting file assignment to this field type
					$assigned_query	= 'SELECT COUNT(value)'
						. ' FROM #__flexicontent_fields_item_relations AS rel'
						. ' JOIN #__flexicontent_fields AS fi ON fi.id = rel.field_id'
						. ' WHERE fi.field_type = ' . $this->_db->Quote($field_type)
						. ' AND value = f.id'
						;
					$columns[] = '('.$assigned_query.') AS assigned_'.$field_type;
				}
			}
			$columns[] = 'CASE WHEN level.title IS NULL THEN CONCAT_WS(\'\', \'deleted:\', f.access) ELSE level.title END AS access_level';
		}
		
		$query = 'SELECT '. implode(', ', $columns)
			. ' FROM #__flexicontent_files AS f'
			. $join
			. $where
			//. ' GROUP BY f.id'
			//. $having
			. (!$ids_only ? $orderby : '')
			;
		
		return $query;
	}
	
	
	/**
	 * Method to build files used by a given item 
	 *
	 * @access private
	 * @return integer
	 * @since 1.0
	 */
	function getItemFiles($item_id=0)
	{
		$query = $this->_buildQuery( $assigned_fields=array(), $ids_only=true, $item_id );
		$this->_db->setQuery($query);

		$items = $this->_db->loadColumn();
		$items = $items ?: array();
		return $items;
	}
	
	
	/**
	 * Method to build the orderby clause of the query for the files
	 *
	 * @access private
	 * @return string
	 * @since 1.0
	 */
	function _buildContentOrderBy()
	{
		$filter_order     = $this->getState( 'filter_order' );
		$filter_order_Dir = $this->getState( 'filter_order_Dir' );
		
		if ($filter_order=='f.filename_displayed') $filter_order = ' CASE WHEN f.filename_original<>"" THEN f.filename_original ELSE f.filename END ';
		$orderby 	= ' ORDER BY '.$filter_order.' '.$filter_order_Dir.', f.filename';

		return $orderby;
	}


	function _buildContentJoin()
	{
		$join = ' JOIN #__users AS u ON u.id = f.uploaded_by';
		return $join;
	}


	/**
	 * Method to build the where clause of the query for the files
	 *
	 * @access private
	 * @return string
	 * @since 1.0
	 */
	function _buildContentWhere()
	{
		$app    = JFactory::getApplication();
		$user   = JFactory::getUser();
		$jinput = $app->input;
		$option = $jinput->get('option', '', 'cmd');

		$where = array();

		$field  = $this->getField();
		$params = $field ? $field->parameters : new JRegistry();

		// Limit listed files to specific uploader,  1: current user, 0: any user, and respect 'filter_uploader' URL variable
		$limit_by_uploader = 0;

		// Calculate a default value for limiting to 'media' or 'secure' folder,  0: media folder, 1: secure folder, 2: no folder limitation AND respect 'filter_secure' URL variable
		$default_dir = 2;
		if ($field)
		{
			if (in_array($field->field_type, array('file', 'image')))
				$default_dir = 1;  // 'secure' folder
			else if (in_array($field->field_type, array('minigallery')))
				$default_dir = 0;  // 'media' folder
		}
		$target_dir = $params->get('target_dir', $default_dir);
		
		// Handles special cases of fields, that have special rules for listing specific files only
		if ($field && $field->field_type =='image' && $params->get('image_source', 0) == 0)
		{
			$limit_by_uploader = (int) $params->get('limit_by_uploader', 0);
			$where[] = $params->get('list_all_media_files', 0)
				? ' f.ext IN ("jpg","gif","png","jpeg") '
				: $this->getFilesUsedByImageField($field, $params);
		}

		$scope  = $this->getState( 'scope' );
		$search = $this->getState( 'search' );
		$search = StringHelper::trim( StringHelper::strtolower( $search ) );
		
		$filter_lang			= $app->getUserStateFromRequest(  $option.'.'.$this->viewid.'.filter_lang',      'filter_lang',      '',          'string' );
		$filter_uploader  = $app->getUserStateFromRequest(  $option.'.'.$this->viewid.'.filter_uploader',  'filter_uploader',  0,           'int' );
		$filter_url       = $app->getUserStateFromRequest(  $option.'.'.$this->viewid.'.filter_url',       'filter_url',       '',          'word' );
		$filter_secure    = $app->getUserStateFromRequest(  $option.'.'.$this->viewid.'.filter_secure',    'filter_secure',    '',          'word' );
		$filter_ext       = $app->getUserStateFromRequest(  $option.'.'.$this->viewid.'.filter_ext',       'filter_ext',       '',          'alnum' );
		
		
		$permission = FlexicontentHelperPerm::getPerm();
		$CanViewAllFiles = $permission->CanViewAllFiles;

		// Limit via parameter, 2: List any file and respect 'filter_secure' URL variable, 1: limit to secure, 0: limit to media
		if ( strlen($target_dir) && $target_dir!=2 )
		{
			$filter_secure = $target_dir ? 'S' : 'M';   // force secure / media
		}

		// Limit via parameter, 1: limit to current user as uploader, 0: list files from any uploader, and respect 'filter_uploader' URL variable
		if ($limit_by_uploader) {
			$where[] = ' uploaded_by = ' . $user->id;
		} else if ( !$CanViewAllFiles ) {
			$where[] = ' uploaded_by = ' . (int)$user->id;
		} else if ( $filter_uploader ) {
			$where[] = ' uploaded_by = ' . $filter_uploader;
		}
		
		if ( $filter_lang ) {
			$where[] = ' language = '. $this->_db->Quote( $filter_lang );
		}
		
		if ( $filter_url ) {
			if ( $filter_url == 'F' ) {
				$where[] = ' url = 0';
			} else if ($filter_url == 'U' ) {
				$where[] = ' url = 1';
			}
		}

		if ( $filter_secure ) {
			if ( $filter_secure == 'M' ) {
				$where[] = ' secure = 0';
			} else if ($filter_secure == 'S' ) {
				$where[] = ' secure = 1';
			}
		}

		if ( $filter_ext ) {
			$where[] = ' ext = ' . $this->_db->Quote( $filter_ext );
		}
		
		if ($search)
		{
			$escaped_search = $this->_db->escape( $search, true );
			
			$search_where = array();
			if ($scope == 1 || $scope == 0) {
				$search_where[] = ' LOWER(f.filename) LIKE '.$this->_db->Quote( '%'.$escaped_search.'%', false );
				$search_where[] = ' LOWER(f.filename_original) LIKE '.$this->_db->Quote( '%'.$escaped_search.'%', false );
			}
			if ($scope == 2 || $scope == 0) {
				$search_where[] = ' LOWER(f.altname) LIKE '.$this->_db->Quote( '%'.$escaped_search.'%', false );
			}
			if ($scope == 3 || $scope == 0) {
				$search_where[] = ' LOWER(f.description) LIKE '.$this->_db->Quote( '%'.$escaped_search.'%', false );
			}
			$where[] = '( '. implode( ' OR ', $search_where ) .' )';
		}

		$where 		= ( count( $where ) ? ' WHERE ' . implode( ' AND ', $where ) : '' );

		return $where;
	}
	
	
	/**
	 * Method to build the having clause of the query for the files
	 *
	 * @access private
	 * @return string
	 * @since 1.0
	 */
	function _buildContentHaving()
	{
		$app    = JFactory::getApplication();
		$jinput = $app->input;
		$option = $jinput->get('option', '', 'cmd');
		
		$filter_assigned	= $app->getUserStateFromRequest(  $option.'.'.$this->viewid.'.filter_assigned', 'filter_assigned', '', 'word' );
		
		$having = '';
		
		if ( $filter_assigned ) {
			if ( $filter_assigned == 'O' ) {
				$having = ' HAVING COUNT(rel.fileid) = 0';
			} else if ($filter_assigned == 'A' ) {
				$having = ' HAVING COUNT(rel.fileid) > 0';
			}
		}
		
		return $having;
	}
	
	
	/**
	 * Method to build the query for file uploaders according to current filtering
	 *
	 * @access private
	 * @return integer
	 * @since 1.0
	 */
	function _buildQueryUsers()
	{
		// Get the WHERE and ORDER BY clauses for the query
		$where = $this->_buildContentWhere();
		
		$query = 'SELECT u.id,u.name'
		. ' FROM #__flexicontent_files AS f'
		. ' LEFT JOIN #__users AS u ON u.id = f.uploaded_by'
		. $where
		. ' GROUP BY u.id'
		. ' ORDER BY u.name'
		;
		return $query;
	}


	/**
	 * Method to build find the (id of) files used by an image field
	 *
	 * @access public
	 * @return integer
	 * @since 3.2
	 */
	function getFilesUsedByImageField($field, $params)
	{
		// Get configuration parameters
		$target_dir = (int) $params->get('target_dir', 1);
		$securepath = JPath::clean(($target_dir ? COM_FLEXICONTENT_FILEPATH : COM_FLEXICONTENT_MEDIAPATH).DS);

		// Retrieve usage of images for the given field from the DB
		$query = 'SELECT value'
			. ' FROM #__flexicontent_fields_item_relations'
			. ' WHERE field_id = '. (int) $field->id .' AND value<>"" ';
		$this->_db->setQuery($query);
		$values = $this->_db->loadColumn();

		// Create original filenames array skipping any empty records
		$filenames = array();
		foreach ( $values as $value )
		{
			if ( empty($value) ) continue;
			$value = @ unserialize($value);

			if ( empty($value['originalname']) ) continue;
			$filenames[$value['originalname']] = 1;
		}
		$filenames = array_keys($filenames);

		// Eliminate records that have no original files
		$existing_files = array();
		foreach($filenames as $filename)
		{
			if (!$filename) continue;  // Skip empty values
			if (file_exists($securepath . $filename))
			{
				$existing_files[$this->_db->Quote($filename)] = 1;
			}
		}
		$filenames = $existing_files;

		if (!$filenames) return '';  // No files found

		$query = 'SELECT id'
			.' FROM #__flexicontent_files'
			.' WHERE '
			.'  filename IN ('.implode(',', array_keys($filenames)).')'
			.($target_dir != 2 ? '  AND secure = '. (int)$target_dir : '');
		$this->_db->setQuery($query);
		$file_ids = $this->_db->loadColumn();

		return !$file_ids ? '' : ' f.id IN ('.implode(', ', $file_ids).')';
	}


	/**
	 * Method to get file uploaders according to current filtering (Currently not used ?)
	 *
	 * @access public
	 * @return object
	 */
	function getUsers()
	{
		// Lets load the files if it doesn't already exist
		if (empty($this->_users))
		{
			$query = $this->_buildQueryUsers();
			$this->_users = $this->_getList($query);
		}

		return $this->_users;
	}
	
	
	/**
	 * Method to find fields using DB mode when given a field type,
	 * this is meant for field types that may or may not use files from the DB
	 *
	 * @access	public
	 * @return	string $msg
	 * @since	1.
	 */
	function getFieldsUsingDBmode($field_type)
	{
		// Some fields may not be using DB, create a limitation for them
		switch($field_type) {
			case 'image':
				$query = "SELECT id FROM #__flexicontent_fields WHERE field_type='image' AND attribs NOT LIKE '%image_source=1%'";
				$this->_db->setQuery($query);
				$field_ids = $this->_db->loadColumn();
				break;
			
			default:
				$field_ids = array();
				break;
		}
		return $field_ids;
	}
	
	
	/**
	 * Method to get items using files VIA (single property) field types that store file ids !
	 *
	 * @access public
	 * @return object
	 */
	function getItemsSingleprop( $field_types=array('file','minigallery'), $file_ids=array(), $count_items=false, $ignored=false )
	{
		$app    = JFactory::getApplication();
		$user   = JFactory::getUser();
		$jinput = $app->input;
		$option = $jinput->get('option', '', 'cmd');
		
		$filter_uploader  = $app->getUserStateFromRequest( $option.'.'.$this->viewid.'.filter_uploader',  'filter_uploader',  0,   'int' );
		
		$field_type_list = $this->_db->Quote( implode( "','", $field_types ), $escape=false );
		
		$where = array();
		
		$file_ids_list = '';
		if ( count($file_ids) ) {
			$file_ids_list = ' AND f.id IN (' . "'". implode("','", $file_ids)  ."')";
		} else {
			$permission = FlexicontentHelperPerm::getPerm();
			$CanViewAllFiles = $permission->CanViewAllFiles;
			
			if ( !$CanViewAllFiles ) {
				$where[] = ' f.uploaded_by = ' . (int)$user->id;
			} else if ( $filter_uploader ) {
				$where[] = ' f.uploaded_by = ' . $filter_uploader;
			}
		}
		
		if ( isset($ignored['item_id']) ) {
			$where[] = ' i.id!='. (int)$ignored['item_id'];
		}
		
		$where = ( count( $where ) ? ' WHERE ' . implode( ' AND ', $where ) : '' );
		$groupby = !$count_items  ?  ' GROUP BY i.id'  :  ' GROUP BY f.id';   // file maybe used in more than one fields or ? in more than one values for same field
		$orderby = !$count_items  ?  ' ORDER BY i.title ASC'  :  '';
		
		// File field relation sub query
		$query = 'SELECT '. ($count_items  ?  'f.id as file_id, COUNT(i.id) as item_count'  :  'i.id as id, i.title')
			. ' FROM #__content AS i'
			. ' JOIN #__flexicontent_fields_item_relations AS rel ON rel.item_id = i.id'
			. ' JOIN #__flexicontent_fields AS fi ON fi.id = rel.field_id AND fi.field_type IN ('. $field_type_list .')'
			. ' JOIN #__flexicontent_files AS f ON f.id=rel.value '. $file_ids_list
			//. ' JOIN #__users AS u ON u.id = f.uploaded_by'
			. $where
			. $groupby
			. $orderby
			;
		//echo nl2br( "\n".$query."\n");
		$this->_db->setQuery( $query );
		$_item_data = $this->_db->loadObjectList($count_items ? 'file_id' : 'id');

		$items = array();
		if ($_item_data) foreach ($_item_data as $item)
		{
			if ($count_items) {
				$items[$item->file_id] = ((int) @ $items[$item->file_id]) + $item->item_count;
			} else {
				$items[$item->title] = $item;
			}
		}
		
		//echo "<pre>"; print_r($items); exit;
		return $items;
	}
	
	
	/**
	 * Method to get items using files VIA (multi property) field types that store file as as property either file id or filename!
	 *
	 * @access public
	 * @return object
	 */
	function getItemsMultiprop( $field_props=array('image'=>'originalname'), $value_props=array('image'=>'filename') , $file_ids=array(), $count_items=false, $ignored=false )
	{
		$app    = JFactory::getApplication();
		$user   = JFactory::getUser();
		$jinput = $app->input;
		$option = $jinput->get('option', '', 'cmd');
		
		$filter_uploader  = $app->getUserStateFromRequest( $option.'.'.$this->viewid.'.filter_uploader',  'filter_uploader',  0,   'int' );
		
		$where = array();
		
		$file_ids_list = '';
		if ( count($file_ids) ) {
			$file_ids_list = ' AND f.id IN (' . "'". implode("','", $file_ids)  ."')";
		} else {
			$permission = FlexicontentHelperPerm::getPerm();
			$CanViewAllFiles = $permission->CanViewAllFiles;
			
			if ( !$CanViewAllFiles ) {
				$where[] = ' f.uploaded_by = ' . (int)$user->id;
			} else if ( $filter_uploader ) {
				$where[] = ' f.uploaded_by = ' . $filter_uploader;
			}
		}
		
		if ( isset($ignored['item_id']) ) {
			$where[] = ' i.id!='. (int)$ignored['item_id'];
		}
		
		$where 		= ( count( $where ) ? ' WHERE ' . implode( ' AND ', $where ) : '' );
		$groupby = !$count_items  ?  ' GROUP BY i.id'  :  ' GROUP BY f.id';   // file maybe used in more than one fields or ? in more than one values for same field
		$orderby = !$count_items  ?  ' ORDER BY i.title ASC'  :  '';
		
		// Serialized values are like : "__field_propname__";s:33:"__value__"
		$format_str = 'CONCAT("%%","\"%s\";s:%%:%%\"",%s,"\"%%")';
		$items = array();
		$files = array();
		
		foreach ($field_props as $field_type => $field_prop)
		{
			// Some fields may not be using DB, create a limitation for them
			$field_ids = $this->getFieldsUsingDBmode($field_type);
			$field_ids_list = !$field_ids  ?  ""  :  " AND fi.id IN ('". implode("','", $field_ids) ."')";
			
			// Create a matching condition for the value depending on given configuration (property name of the field, and value property of file: either id or filename or ...)
			$value_prop = $value_props[$field_type];
			$like_str = $this->_db->escape( 'f.'.$value_prop, false );
			$like_str = sprintf( $format_str, $field_prop, $like_str );
			
			// File field relation sub query
			$query = 'SELECT '. ($count_items  ?  'f.id as file_id, COUNT(i.id) as item_count'  :  'i.id as id, i.title')
				. ' FROM #__content AS i'
				. ' JOIN #__flexicontent_fields_item_relations AS rel ON rel.item_id = i.id'
				. ' JOIN #__flexicontent_fields AS fi ON fi.id = rel.field_id AND fi.field_type IN ('. $this->_db->Quote( $field_type ) .')' . $field_ids_list
				. ' JOIN #__flexicontent_files AS f ON rel.value LIKE ' . $like_str . ' AND f.'.$value_prop.'<>""' . $file_ids_list
				//. ' JOIN #__users AS u ON u.id = f.uploaded_by'
				. $where
				. $groupby
				. $orderby
				;
			//echo nl2br( "\n".$query."\n");
			$this->_db->setQuery( $query );
			$_item_data = $this->_db->loadObjectList($count_items ? 'file_id' : 'id');

			if ($_item_data) foreach ($_item_data as $item)
			{
				if ($count_items) {
					$items[$item->file_id] = ((int) @ $items[$item->file_id]) + $item->item_count;
				} else {
					$items[$item->title] = $item;
				}
			}
			//echo "<pre>"; print_r($_item_data); exit;
		}
		
		//echo "<pre>"; print_r($items); exit;
		return $items;
	}
	
	
	
	/**
	 * Method to count the field relations (assignments) of a file in a multi-property field
	 *
	 * @access	public
	 * @return	string $msg
	 * @since	1.
	 */
	function countFieldRelationsMultiProp(&$rows, $value_prop, $field_prop, $field_type)
	{
		if (!$rows || !count($rows)) return array();  // No file records to check
		
		// Some fields may not be using DB, create a limitation for them
		$field_ids = $this->getFieldsUsingDBmode($field_type);
		$field_ids_list = !$field_ids  ?  ""  :  " AND fi.id IN ('". implode("','", $field_ids) ."')";
		
		$format_str = 'CONCAT("%%","\"%s\";s:%%:%%\"",%s,"\"%%")';
		$items = array();
		
		foreach ($rows as $row) $row_ids[] = $row->id;
		$file_ids_list = "'". implode("','", $row_ids) . "'";
		
		// Serialized values are like : "__field_propname__";s:33:"__value__"
		$format_str = 'CONCAT("%%","\"%s\";s:%%:%%\"",%s,"\"%%")';
		
		// Create a matching condition for the value depending on given configuration (property name of the field, and value property of file: either id or filename or ...)
		$like_str = $this->_db->escape( 'f.'.$value_prop, false );
		$like_str = sprintf( $format_str, $field_prop, $like_str );
		
		$query	= 'SELECT f.id as id, COUNT(rel.item_id) as count, GROUP_CONCAT(DISTINCT rel.item_id SEPARATOR  ",") AS item_list'
				. ' FROM #__flexicontent_fields_item_relations AS rel'
				. ' JOIN #__flexicontent_fields AS fi ON fi.id = rel.field_id AND fi.field_type = ' . $this->_db->Quote($field_type) . $field_ids_list
				. ' JOIN #__flexicontent_files AS f ON rel.value LIKE ' . $like_str . ' AND f.'.$value_prop.'<>""'
				. ' WHERE f.id IN('. $file_ids_list .')'
				. ' GROUP BY f.id'
				;
		$this->_db->setQuery($query);
		$assigned_data = $this->_db->loadObjectList('id');

		foreach($rows as $row)
		{
			$row->{'assigned_'.$field_type} = (int) @ $assigned_data[$row->id]->count;
			if (@ $assigned_data[$row->id]->item_list)
				$row->item_list[$field_type] = $assigned_data[$row->id]->item_list;
		}
	}
	
	
	/**
	 * Method to count the field relations (assignments) of a file in a single-property field that stores file ids !
	 *
	 * @access	public
	 * @return	string $msg
	 * @since	1.
	 */
	function countFieldRelationsSingleProp(&$rows, $field_type)
	{
		if ( !count($rows) ) return;
		
		foreach ($rows as $row)
		{
			$file_id_arr[] = $row->id;
		}
		$query	= 'SELECT f.id as file_id, COUNT(rel.item_id) as count, GROUP_CONCAT(DISTINCT rel.item_id SEPARATOR  ",") AS item_list'
				. ' FROM #__flexicontent_files AS f'
				. ' JOIN #__flexicontent_fields_item_relations AS rel ON f.id = rel.value'
				. ' JOIN #__flexicontent_fields AS fi ON fi.id = rel.field_id AND fi.field_type = ' . $this->_db->Quote($field_type)
				. ' WHERE f.id IN ('. implode( ',', $file_id_arr ) .')'
				. ' GROUP BY f.id'
				;
		$this->_db->setQuery($query);
		$assigned_data = $this->_db->loadObjectList('file_id');

		foreach ($rows as $row)
		{
			$row->{'assigned_'.$field_type} = (int) @ $assigned_data[$row->id]->count;
			if (@ $assigned_data[$row->id]->item_list)
				$row->item_list[$field_type] = $assigned_data[$row->id]->item_list;
		}
	}
	
	
}
