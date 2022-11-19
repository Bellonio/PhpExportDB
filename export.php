<?php
	/*===========================================
	* @author Giulio Bellone <bellonegiulio@gmail.com>
	* @copyright 2022 Giulio Bellone
	*
	* This code makes a complete dumb of the all tables (or just the ones you specified)  by making simple sql queries.
	* Note:
	*	- the geometry (geometry, point, linestring, ...) fields are written in HEX format to be sure the import will work
	*	- automatically fields that can be NULL, become NULL if empty, or if can't be NULL became '' (for JSON field '{}')
	*	
	*	! ! ! 
	*	 THE TABLES ARE WRITTEN IN ALPHABETICAL ORDER, SO DURING THE IMPORT,
	*	 MAKE SURE TO DISABLE FOREIGN KEY CHECK
	*	! ! !
	*
	* Usage:
	* 	simply modify database credentials and the tables to export. Then run this file and it will create the .sql file.
	*
	* Output a JSON:
	*  - success: 			true / false
	*	- file: 				filename / NULL
	*	- message: 		error_message / NULL
	*/

	/* ! ! ! ! ! ! ! ! ! ! ! ! ! ! ! ! ! ! ! ! !
	* REMEMBER TO CHANGE THE CREDENTIALS
	*  ! ! ! ! ! ! ! ! ! ! ! ! ! ! ! ! ! ! ! ! !
	*/
	define("HOST", 		"host_to_change");
	define("DBNAME", 	"dbname_to_change");
	define("DBUSER", 	"dbuser_to_change");
	define("DBPASS", 	"dbpass_to_change");
	
	/* -----------------------------------------------
	* If you don't want to export all tables:
	*   - de-comment the line
	*	- change with the name of the table in the database (the order doesn't matter)
	*/
	//$EXPORT_T = array("table_1","table_2","table_3");
	
	/* -----------------------------------------------
	* Each 100 rows it create new INSERT INTO query.
	*  Change it as you prefer.
	*/
	define("NUMBER_FOR_EACH_INSERT", 100);
	
	$TYPES_TO_HEX = array("geometry","point","linestring","polygon","multipoint","multilinestring","multipolygon","geometrycollection");
	//----------------------------------------------------------
	
	class Database Extends PDO {
		private $host  = HOST;
		private $user  = DBUSER;
		private $pass = DBPASS;
		private $dbname = DBNAME;

		public function __construct() {
			try {
				parent::__construct('mysql:host=' . $this->host . ';dbname=' . $this->dbname, $this->user, $this->pass, array(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION));
				$this->exec("SET CHARACTER SET utf8");
			}catch (\PDOException $e){
				die(json_encode(array("success"=>false, "message"=>'CONNECTION ERROR: ' . $e->getMessage(), "file"=>NULL)));
			}
		}
	}
	$dbh = new Database();
	
		
	try{
		$sql = "SHOW TABLES";
		$sth = $dbh->prepare($sql);
		if( !$sth->execute()) die(json_encode(array("success"=>false, "message"=>"Line 71<br>".$sql."<br>".$sth->errorInfo()[2], "file"=>NULL)));
		$res_tables = $sth->fetchAll();
		
		$tables_name = array();
		foreach($res_tables as $row){
			if( isset($EXPORT_T) && !in_array($row[0], $EXPORT_T) ) continue;
			
			$tables_name[] = $row[0];
		}
		
		
		$content = "-- Database Export: ".date("Y-m-d H:i")."\n\n";
		foreach($tables_name as $counter_t=>$t){
			
			$sql = "SHOW CREATE TABLE $t";
			$sth = $dbh->prepare($sql);
			if( !$sth->execute()) die(json_encode(array("success"=>false, "message"=>"Line 87<br>".$sql."<br>".$sth->errorInfo()[2], "file"=>NULL)));
			$res_create = $sth->fetch(PDO::FETCH_NUM)[1];
			$res_create = str_replace("CREATE TABLE", "CREATE TABLE IF NOT EXISTS", $res_create);
			
			$content .= "$res_create;\n\n";


			$sql = "SHOW COLUMNS FROM $t";
			$sth = $dbh->prepare($sql);
			if( !$sth->execute()) die(json_encode(array("success"=>false, "message"=>"Line 96<br>".$sql."<br>".$sth->errorInfo()[2], "file"=>NULL)));
			$res_types = $sth->fetchAll(PDO::FETCH_ASSOC);
			
			$columns = array();
			$columns_hex = array();
			$columns_can_null = array();
			$columns_json = array();
			foreach($res_types as $col_type){
				if( in_array($col_type["Type"], $TYPES_TO_HEX) ){
					$columns_hex[] = $col_type["Field"];
					$columns[] = "CONCAT('0x', HEX(".$col_type["Field"].")) AS ".$col_type["Field"];
				}else{
					$columns[] = $col_type["Field"];
				}
				
				if( $col_type["Type"]=="json" ) $columns_json[] = $col_type["Field"];				
				if( $col_type["Null"]=="YES" ) $columns_can_null[] = $col_type["Field"];
			}
			
			$columns = implode(",", $columns);
			
			$sql = "SELECT $columns FROM $t";
			$sth = $dbh->prepare($sql);
			if( !$sth->execute()) die(json_encode(array("success"=>false, "message"=>"Line 119<br>".$sql."<br>".$sth->errorInfo()[2], "file"=>NULL)));
			$res_rows = $sth->fetchAll(PDO::FETCH_ASSOC);

			$content .= "INSERT INTO $t VALUES ";

			$counter = 0;
			foreach($res_rows as $index=>$row){
				
				$fields = array();
				foreach($row as $key=>$field){
					if( is_null($field) || $field=="null" || $field=="" ){
						$field = "NULL";
					}
					if( !in_array($key, $columns_can_null) && $field=="NULL" ){
						if( !in_array($key, $columns_json) ){
							$field = "";
						}else{ $field = "{}"; }
					}
					
					if( $field!="NULL" && !is_numeric($field) && !in_array($key, $columns_hex) ){
						$field = str_replace("'", "\'", $field);
						$field = str_replace('"', '\"', $field);
						$field = "'$field'";
					}
					
					$fields[] = $field;
				}
				
				$content .= "(".implode(",", $fields)."),";
				
				if( $counter==(NUMBER_FOR_EACH_INSERT-1) ){
					$content = substr($content, 0, strlen($content)-1).";";
					if( $index<count($res_rows)-1 ){
						$content .= "\nINSERT INTO $t VALUES ";
					}
					$counter = -1;
				}else{
					if( $index==count($res_rows)-1 ){
						$content = substr($content, 0, strlen($content)-1).";";
					}
				}
				$counter++;
			}
			
			if( $counter_t<count($tables_name)-1 ){
				$content .= "\n\n\n";
			}
		}
		$content = substr($content, 0, strlen($content)-1).";\n\n";
		
		
		$backup_name = "db-export_".date("YmdHis").".sql";
		
		if( !file_exists("./exports/") ){
			mkdir("exports");
		}
		$f = fopen("./exports/$backup_name", "w");
		$res = fwrite($f, $content);
		fclose($f);
		
		echo json_encode(array("success"=>($res!==false), "file"=>$backup_name, "message"=>NULL));
	}catch(\PDOException $e){
		$message = $sql."\n\n".$e->getMessage();
		
		echo json_encode(array("success"=>false, "message"=>$message, "file"=>NULL));
		exit;
	}
?>
