<?php
/**
* @version 0.6 stable $Id: helper.php yannick berges
* @package Joomla
* @subpackage FLEXIcontent
* @copyright (C) 2015 Berges Yannick - www.com3elles.com
* @license GNU/GPL v2

* special thanks to ggppdk and emmanuel dannan for flexicontent
* special thanks to my master Marc Studer

* FLEXIadmin module is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
**/

//blocage des accés directs sur ce script
defined('_JEXEC') or die('Restricted access');

require_once (JPATH_ADMINISTRATOR.DS.'components'.DS.'com_flexicontent'.DS.'defineconstants.php');
require_once (JPATH_SITE.DS.'components'.DS.'com_content'.DS.'helpers'.DS.'route.php');
require_once (JPATH_SITE.DS.'components'.DS.'com_flexicontent'.DS.'helpers'.DS.'route.php');

JTable::addIncludePath(JPATH_ADMINISTRATOR.DS.'components'.DS.'com_flexicontent'.DS.'tables');

require_once (JPATH_SITE.DS."components".DS."com_flexicontent".DS."classes".DS."flexicontent.fields.php");
require_once (JPATH_SITE.DS."components".DS."com_flexicontent".DS."classes".DS."flexicontent.helper.php");
require_once (JPATH_SITE.DS."components".DS."com_flexicontent".DS."helpers".DS."permission.php");

require_once (JPATH_SITE.DS."components".DS."com_flexicontent".DS."models".DS.FLEXI_ITEMVIEW.".php");


class modFlexigooglemapHelper
{
	public static function getItemsLocations(&$params)
	{
		$fieldaddressid = $params->get('fieldaddressid');
		if ( empty($fieldaddressid) )
		{
			echo '<div class="alert alert-warning">' . JText::_('FLEXI_GOOGLEMAP_ADDRESSFORGOT') .'</div>';
			return null;
		}

		$treeinclude = $params->get('treeinclude', 1);  // by default include children categories
		global $globalcats;
		$catids = $params->get('catid');

		// Make sure categories is an array
		$catids = is_array($catids) ? $catids : array($catids);

		// Retrieve extra categories, such children or parent categories
		$catids_arr = flexicontent_cats::getExtraCats($catids, $treeinclude, array());

		// Check if zero allowed categories
		if (empty($catids_arr))
		{
			return array();
		}

		$count = $params->get('count');
		$forced_itemid = $params->get('forced_itemid', 0);

		$db = JFactory::getDbo();
		$queryLoc = 'SELECT a.id, a.title, b.field_id, b.value , a.catid '
			.' FROM #__content  AS a'
			.' JOIN #__flexicontent_cats_item_relations AS rel ON rel.itemid = a.id '
			.' LEFT JOIN #__flexicontent_fields_item_relations AS b ON a.id = b.item_id '
			.' WHERE b.field_id = '.$fieldaddressid.' AND rel.catid IN (' . implode(',', $catids_arr) . ') AND state = 1'
			.' ORDER BY title '.$count
			;
		$db->setQuery( $queryLoc );
		$itemsLoc = $db->loadObjectList();

		foreach ($itemsLoc as &$itemLoc) 
		{
			$itemLoc->link = JRoute::_(FlexicontentHelperRoute::getItemRoute($itemLoc->id, $itemLoc->catid, $forced_itemid, $itemLoc));
		}

		return $itemsLoc;
	}


	public static function renderMapLocations($params)
	{
		$uselink = $params->get('uselink', '');
		$useadress = $params->get('useadress', '');

		$linkmode = $params->get('linkmode', '');
		$readmore = $params->get('readmore', '');

		$usedirection = $params->get('usedirection', '');
		$directionname = $params->get('directionname', '');

		$infotextmode = $params->get('infotextmode', '');
		$relitem_html = $params->get('relitem_html','');

		$fieldaddressid = $params->get('fieldaddressid');
		$forced_itemid = $params->get('forced_itemid', 0);

		$mapLocations = array();

		// Fixed category mode
		if ($params->get('catidmode') == 0)
		{
			$itemsLocations = modFlexigooglemapHelper::getItemsLocations($params);
			foreach ($itemsLocations as $itemLoc)
			{
				if ( empty($itemLoc->value) ) continue;   // skip empty value

				$coord = unserialize ($itemLoc->value);
				$lat = $coord['lat'];
				$lon = $coord['lon'];

				if ( empty($lat) && empty($lon) ) continue;    // skip empty value

				$title = rtrim( addslashes($itemLoc->title) );

				$link = '';
				if ($uselink)
				{
					$link = $itemLoc->link;
					$link = '<p class="link"><a href="'.$link.'" target="'.$linkmode.'">'.JText::_($readmore).'</a></p>';
					$link = addslashes($link);
				}

				$addr = '';
				if ($useadress)
				{
					if ( !isset($coord['addr_display']) ) $coord['addr_display'] = '';
					$addr = '<p>'.$coord['addr_display'].'</p>';
					$addr = addslashes($addr);
					$addr = preg_replace("/(\r\n|\n|\r)/", " ", $addr);
				}

				$linkdirection = '';
				if ($usedirection)
				{
					$adressdirection = $addr;
					$linkdirection= '<div class="directions"><a href="https://maps.google.com/maps?q='.$adressdirection.'" target="_blank" class="direction">'.JText::_($directionname).'</a></div>';
				}

				$contentwindows = $infotextmode  ?  $relitem_html  :  $addr .' '. $link;

				$coordinates = $lat .','. $lon;
				$mapLocations[] = "['<h4 class=\"fleximaptitle\">$title</h4>$contentwindows $linkdirection'," . $coordinates . "]\r\n";
			}
		}

		// Current category mode
		else
		{
			// Get items of current view
			global $fc_list_items;
			if ( empty($fc_list_items) )
			{
				$fc_list_items = array();
			}
			foreach ($fc_list_items as $address)
			{
				if ( ! isset( $address->fieldvalues[$fieldaddressid][0]) ) continue;   // skip empty value

				$coord = unserialize ($address->fieldvalues[$fieldaddressid][0]);
				$lat = $coord['lat'];
				$lon = $coord['lon'];

				if ( empty($lat) && empty($lon) ) continue;    // skip empty value

				$title = addslashes($address->title);

				$link = '';
				if ($uselink)
				{
					$link = JRoute::_(FlexicontentHelperRoute::getItemRoute($address->id, $address->catid, $forced_itemid, $address));
					$link = '<p class="link"><a href="'.$link.'" target="'.$linkmode.'">'.JText::_($readmore).'</a></p>';
					$link = addslashes($link);
				}

				$addr = '';
				if ($useadress)
				{
					if ( !isset($coord['addr_display']) ) $coord['addr_display'] = '';
					$addr = '<p>'.$coord['addr_display'].'</p>';
					$addr = addslashes($addr);
					$addr = preg_replace("/(\r\n|\n|\r)/", " ", $addr);
				}

				$linkdirection = '';
				if ($usedirection)
				{
					$adressdirection = $addr;
					$linkdirection= '<div class="directions"><a href="https://maps.google.com/maps?q='.$adressdirection.'" target="_blank" class="direction">'.JText::_($directionname).'</a></div>';
				}

				$contentwindows = $infotextmode  ?  $relitem_html  :  $addr .' '. $link;

				$coordinates = $lat .','. $lon;
				$mapLocations[] = "['<h4 class=\"fleximaptitle\">$title</h4>$contentwindows $linkdirection'," . $coordinates . "]\r\n";
			}
		}

		return $mapLocations;
	}


	public static function getMarkerURL(&$params)
	{
		$markerimage = $params->get('markerimage');
		$markercolor = $params->get('markercolor');
		$lettermarker = $params->get('lettermarker');

		$lettermarkermode = $params->get('lettermarkermode', 0);  // compatibility with old parameter
		$markermode = $params->get('markermode', $lettermarkermode);
		
		if ($markermode==1)
		{
			$letter = "&text=".$lettermarker."&psize=16&font=fonts/arialuni_t.ttf&color=ff330000&scale=1&ax=44&ay=48";
			switch ($markercolor)
			{
				case "red":
					$color ="spotlight-waypoint-b.png";
					break;
				case "green":
					$color ="spotlight-waypoint-a.png";
					break;
				default :
					$color ="spotlight-waypoint-b.png";
					break;
			}

			$icon = "'https://mts.googleapis.com/vt/icon/name=icons/spotlight/" . $color . $letter . "'";	
		}
		else
		{
			$icon = $markerimage ? "'" . JURI::base() . $markerimage . "'" : "''";
		}

		return $icon;
	}
}
