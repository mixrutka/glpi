<?php
/*
 * @version $Id$
 -------------------------------------------------------------------------
 GLPI - Gestionnaire Libre de Parc Informatique
 Copyright (C) 2003-2006 by the INDEPNET Development Team.

 http://indepnet.net/   http://glpi-project.org
 -------------------------------------------------------------------------

 LICENSE

 This file is part of GLPI.

 GLPI is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 GLPI is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with GLPI; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 --------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')){
	die("Sorry. You can't access directly to this file");
	}

// Common DataBase Table Manager Class
class CommonDBTM {

	var $fields	= array();
	var $table="";
	var $type=-1;
	var $dohistory=false;

	function CommonDBTM () {

	}

	// Specific ones : StateItem / Reservation Item
	function getFromDB ($ID) {

		// Make new database object and fill variables
		global $DB;
		if (empty($ID)) return false;

		$query = "SELECT * FROM ".$this->table." WHERE (ID = '$ID')";

		if ($result = $DB->query($query)) {
			if ($DB->numrows($result)==1){
				$this->fields = $DB->fetch_assoc($result);
				return true;
			} else return false;
		} else {
			return false;
		}
	}

	function getEmpty () {
		//make an empty database object
		global $DB;
		if ($fields = $DB->list_fields($this->table)){
			foreach ($fields as $key => $val){
				$this->fields[$key] = "";
			}
		} else return false;
		$this->post_getEmpty();
		return true;
	}
	function post_getEmpty () {
	}

	function updateInDB($updates)  {

		global $DB,$CFG_GLPI;

		for ($i=0; $i < count($updates); $i++) {
			$query  = "UPDATE `".$this->table."` SET `";
			$query .= $updates[$i]."`";

			if ($this->fields[$updates[$i]]=="NULL"){
				$query .= " = ";
				$query .= $this->fields[$updates[$i]];
			} else {
				$query .= " = '";
				$query .= $this->fields[$updates[$i]]."'";
			}
			$query .= " WHERE ID='";
			$query .= $this->fields["ID"];	
			$query .= "'";
			$result=$DB->query($query);
		}

		$this->post_updateInDB($updates);
		cleanAllItemCache($this->fields["ID"],"GLPI_".$this->type);
		cleanRelationCache($this->table);
		return true;
	}

	function post_updateInDB($updates)  {

	}

	function addToDB() {

		global $DB;
		//unset($this->fields["ID"]);
		$nb_fields=count($this->fields);
		if ($nb_fields>0){		

			// Build query
			$query = "INSERT INTO ".$this->table." (";
			$i=0;
			foreach ($this->fields as $key => $val) {
				$fields[$i] = $key;
				$values[$i] = $val;
				$i++;
			}		

			for ($i=0; $i < $nb_fields; $i++) {
				$query .= "`".$fields[$i]."`";
				if ($i!=$nb_fields-1) {
					$query .= ",";
				}
			}
			$query .= ") VALUES (";
			for ($i=0; $i < $nb_fields; $i++) {
				$query .= "'".$values[$i]."'";
				if ($i!=$nb_fields-1) {
					$query .= ",";
				}
			}
			$query .= ")";

			if ($result=$DB->query($query)) {
				$this->fields["ID"]=$DB->insert_id();
				$this->post_addToDB();
				cleanRelationCache($this->table);
				return $this->fields["ID"];
			} else {
				return false;
			}
		} else return false;
	}

	function post_addToDB(){

	}

	function restoreInDB($ID) {
		global $DB,$CFG_GLPI;
		if (in_array($this->table,$CFG_GLPI["deleted_tables"])){
			$query = "UPDATE ".$this->table." SET deleted='N' WHERE (ID = '$ID')";
			if ($result = $DB->query($query)) {
				return true;
			} else {
				return false;
			}
		} else return false;
	}
	function deleteFromDB($ID,$force=0) {

		global $DB,$CFG_GLPI;

		if ($force==1||!in_array($this->table,$CFG_GLPI["deleted_tables"])){

			$this->cleanDBonPurge($ID);

			$query = "DELETE from ".$this->table." WHERE ID = '$ID'";

			if ($result = $DB->query($query)) {
				$this->post_deleteFromDB($ID);
				cleanAllItemCache($this->fields["ID"],"GLPI_".$this->type);
				cleanRelationCache($this->table);
				return true;
			} else {
				return false;
			}
		}else {
			$query = "UPDATE ".$this->table." SET deleted='Y' WHERE ID = '$ID'";		
			if ($result = $DB->query($query)){
				cleanAllItemCache($this->fields["ID"],"GLPI_".$this->type);
				cleanRelationCache($this->table);
				return true;
			} else return false;
		}
	}

	function post_deleteFromDB($ID){
	}

	function cleanDBonPurge($ID) {
	}

	// Common functions

	/**
	 * Add an item in the database.
	 *
	 * Add an item in the database with all it's items.
	 *
	 *@param $input array : the _POST vars returned bye the item form when press add
	 *
	 *
	 *@return integer the new ID of the added item
	 *
	 **/
	// specific ones : reservationresa , planningtracking
	function add($input) {
		global $DB;
		// dump status
		unset($input['add']);
		$input=$this->prepareInputForAdd($input);

		if ($input&&is_array($input)){
			$table_fields=$DB->list_fields($this->table);
			// fill array for udpate
			foreach ($input as $key => $val) {
				if ($key[0]!='_'&& isset($table_fields[$key])&&(!isset($this->fields[$key]) || $this->fields[$key] != $input[$key])) {
					$this->fields[$key] = $input[$key];
				}
			}

			if ($newID= $this->addToDB()){
				$this->postAddItem($newID,$input);
				do_hook_function("item_add",array("type"=>$this->type, "ID" => $newID));
				return $newID;
			} else return false;

		} else return false;
	}

	function prepareInputForAdd($input) {
		return $input;
	}

	function postAddItem($newID,$input) {
	}


	/**
	 * Update some elements of an item in the database
	 *
	 * Update some elements of an item in the database.
	 *
	 *@param $input array : the _POST vars returned bye the item form when press update
	 *@param $history boolean : do history log ?
	 *
	 *
	 *@return Nothing (call to the class member)
	 *
	 **/
	// specific ones : reservationresa, planningtracking
	function update($input,$history=1) {

		$input=$this->prepareInputForUpdate($input);
		unset($input['update']);

		if ($this->getFromDB($input["ID"])){
			// Fill the update-array with changes
			$x=0;
			$updates=array();
			foreach ($input as $key => $val) {
				// Secu for null values on history
				if (isset($this->fields[$key])&&is_null($this->fields[$key])) $this->fields[$key]=0;
				if (array_key_exists($key,$this->fields) && $this->fields[$key] != $input[$key]) {
					// Debut logs
					if ($this->dohistory&&$history)
						constructHistory($input["ID"],$this->type,$key,$this->fields[$key],$input[$key]);
					// Fin des logs

					$this->fields[$key] = $input[$key];
					$updates[$x] = $key;
					$x++;
				}
			}

			if(count($updates)){
				list($input,$updates)=$this->pre_updateInDB($input,$updates);

				if ($this->updateInDB($updates)){
					$this->post_updateItem($input,$updates,$history);
					do_hook_function("item_update",array("type"=>$this->type, "ID" => $input["ID"]));
				}
			} 


		}
	}

	function prepareInputForUpdate($input) {
		return $input;
	}

	function post_updateItem($input,$updates,$history=1) {
	}

	function pre_updateInDB($input,$updates) {
		return array($input,$updates);
	}

	/**
	 * Delete an item in the database.
	 *
	 * Delete an item in the database.
	 *
	 *@param $input array : the _POST vars returned bye the item form when press delete
	 *@param $force boolean : force deletion
	 *
	 *
	 *@return Nothing ()
	 *
	 **/
	function delete($input,$force=0) {

		if ($this->getFromDB($input["ID"])){
			$this->pre_deleteItem($input["ID"]);
			$this->deleteFromDB($input["ID"],$force);
			if ($force)
				do_hook_function("item_purge",array("type"=>$this->type, "ID" => $input["ID"]));
			else 
				do_hook_function("item_delete",array("type"=>$this->type, "ID" => $input["ID"]));

			return true;
		} else return false;

	}
	function pre_deleteItem($ID) {

	}
	/**
	 * Restore an item trashed in the database.
	 *
	 * Restore an item trashed in the database.
	 *
	 *@param $input array : the _POST vars returned bye the item form when press restore
	 *
	 *@return Nothing ()
	 *
	 **/
	// specific ones : cartridges / consumables
	function restore($input) {

		$this->restoreInDB($input["ID"]);
		do_hook_function("item_restore",array("type"=>$this->type, "ID" => $input["ID"]));
	}

	function defineOnglets($withtemplate){
		return array();
	}

	function showOnglets($ID,$withtemplate,$actif){
		global $LANG,$CFG_GLPI;

		$target=$_SERVER['PHP_SELF']."?ID=".$ID;
	
		$template="";
		if(!empty($withtemplate)){
			$template="&amp;withtemplate=$withtemplate";
		}
	
		echo "<div id='barre_onglets'><ul id='onglet'>";
	
		if (count($onglets=$this->defineOnglets($withtemplate))){
			//if (empty($withtemplate)&&haveRight("reservation_central","r")&&function_exists("isReservable")){
			//	$onglets[11]=$LANG["title"][35];
			//	ksort($onglets);
			//}
			foreach ($onglets as $key => $val ) {
				echo "<li "; if ($actif==$key){ echo "class='actif'";} echo  "><a href='$target&amp;onglet=$key$template'>".$val."</a></li>";
			}
		}
	
	
		if(empty($withtemplate)){
			echo "<li class='invisible'>&nbsp;</li>";
			echo "<li "; if ($actif=="-1") {echo "class='actif'";} echo "><a href='$target&amp;onglet=-1$template'>".$LANG["title"][29]."</a></li>";
		}
	
	
		display_plugin_headings($target,$this->type,$withtemplate,$actif);
	
		echo "<li class='invisible'>&nbsp;</li>";
	
		if (empty($withtemplate)&&preg_match("/\?ID=([0-9]+)/",$target,$ereg)){
			$ID=$ereg[1];
			$next=getNextItem($this->table,$ID);
			$prev=getPreviousItem($this->table,$ID);
			$cleantarget=preg_replace("/\?ID=([0-9]+)/","",$target);
			if ($prev>0) echo "<li><a href='$cleantarget?ID=$prev'><img src=\"".$CFG_GLPI["root_doc"]."/pics/left.png\" alt='".$LANG["buttons"][12]."' title='".$LANG["buttons"][12]."'></a></li>";
			if ($next>0) echo "<li><a href='$cleantarget?ID=$next'><img src=\"".$CFG_GLPI["root_doc"]."/pics/right.png\" alt='".$LANG["buttons"][11]."' title='".$LANG["buttons"][11]."'></a></li>";
		}
	
		echo "</ul></div>";
	} 

}




?>
