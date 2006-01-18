<?php
/*
 * @version $Id$
 ----------------------------------------------------------------------
 GLPI - Gestionnaire Libre de Parc Informatique
 Copyright (C) 2003-2006 by the INDEPNET Development Team.
 
 http://indepnet.net/   http://glpi.indepnet.org
 ----------------------------------------------------------------------

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
 ------------------------------------------------------------------------
*/
 
// ----------------------------------------------------------------------
// Original Author of file: Julien Dombre
// Purpose of file:
// ----------------------------------------------------------------------

include ("_relpos.php");


class Enterprise {

	var $fields	= array();
	var $updates	= array();
	
	function getfromDB ($ID) {

		$db = new DB;
		$query = "SELECT * FROM glpi_enterprises WHERE (ID = '$ID')";
		
		if ($result = $db->query($query)) {
		if ($db->numrows($result)==1){
			$data = $db->fetch_array($result);
				foreach ($data as $key => $val) {
					$this->fields[$key] = $val;
				}
				return true;
		} else return false;
		} else {
			return false;
		}
	}
	
	function getEmpty () {
	$db = new DB;
	$fields = $db->list_fields("glpi_enterprises");
	$columns = $db->num_fields($fields);
		for ($i = 0; $i < $columns; $i++) {
			$name = $db->field_name($fields, $i);
			$this->fields[$name] = "";
		}
	return true;
	}

	function countContacts() {
		$db = new DB;
		$query = "SELECT * FROM glpi_contact_enterprise WHERE (FK_enterprise = '".$this->fields["ID"]."')";
		if ($result = $db->query($query)) {
			$number = $db->numrows($result);
			return $number;
		} else {
			return false;
		}
	}
	function isUsed() {
		return true;
		$db = new DB;
		$query = "SELECT * FROM glpi_contact_enterprise WHERE (FK_enterprise = '".$this->fields["ID"]."')";
		if ($result = $db->query($query)) {
			if ($db->numrows($result)>0) return true;
			else {
				$query2 = "SELECT * FROM glpi_infocoms WHERE (FK_enterprise = '".$this->fields["ID"]."')";
				$result2 = $db->query($query2);
				if ($db->numrows($result2)>0) return true;
				
					$query="SELECT * from glpi_tracking where computer = '".$this->fields["ID"]."' AND device_type='".ENTERPRISE_TYPE."'";
					$result = $db->query($query);
					if ($db->numrows($result)>0) return true;
				else {
					
				/// TODO : ajouter tous les liens FK_manufacturer !!!
				return false;
				
				}
			}
		
			
		} else return true;
		
	}

	function restoreInDB($ID) {
		$db = new DB;
		$query = "UPDATE glpi_enterprises SET deleted='N' WHERE (ID = '$ID')";
		if ($result = $db->query($query)) {
			return true;
		} else {
			return false;
		}
	}
	
	function updateInDB($updates)  {

		$db = new DB;

		for ($i=0; $i < count($updates); $i++) {
			$query  = "UPDATE glpi_enterprises SET ";
			$query .= $updates[$i];
			$query .= "='";
			$query .= $this->fields[$updates[$i]];
			$query .= "' WHERE ID='";
			$query .= $this->fields["ID"];	
			$query .= "'";
			$result=$db->query($query);
		}
		
	}
	
	function addToDB() {
		
		$db = new DB;

		// Build query
		$query = "INSERT INTO glpi_enterprises (";
		$i=0;
		foreach ($this->fields as $key => $val) {
			$fields[$i] = $key;
			$values[$i] = $val;
			$i++;
		}		
		for ($i=0; $i < count($fields); $i++) {
			$query .= $fields[$i];
			if ($i!=count($fields)-1) {
				$query .= ",";
			}
		}
		$query .= ") VALUES (";
		for ($i=0; $i < count($values); $i++) {
			$query .= "'".$values[$i]."'";
			if ($i!=count($values)-1) {
				$query .= ",";
			}
		}
		$query .= ")";

		$result=$db->query($query);
		return $db->insert_id();

	}

	function deleteFromDB($ID,$force=0) {

		$db = new DB;

		$this->getFromDB($ID);		
		if ($force==1||!$this->isUsed()){
			$query = "DELETE from glpi_enterprises WHERE ID = '$ID'";
			if ($result = $db->query($query)) {

				$job=new Job;

				$query = "SELECT * FROM glpi_tracking WHERE (computer = '$ID'  AND device_type='".ENTERPRISE_TYPE."')";
				$result = $db->query($query);
				$number = $db->numrows($result);
				$i=0;
				while ($i < $number) {
 		  		$job->deleteinDB($db->result($result,$i,"ID"));
					$i++;
				}

				
				// Delete all enterprises associations from infocoms and contract
				$query3 = "DELETE FROM glpi_contract_enterprise WHERE (FK_enterprise = \"$ID\")";
				$result3 = $db->query($query3);
				
				// Delete all contact enterprise associations
				$query2 = "DELETE FROM glpi_contact_enterprise WHERE (FK_enterprise = \"$ID\")";
				if ($result2 = $db->query($query2)) {
					
					
					/// TODO : UPDATE ALL FK_manufacturer to NULL
					return true;
				}
			} else {
				return false;
			}
		} else {
		$query = "UPDATE glpi_enterprises SET deleted='Y' WHERE ID = '$ID'";		
		return ($result = $db->query($query));
		}
	}
	
}

?>
