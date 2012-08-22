<?php

include_once(dirname(__FILE__).DIRECTORY_SEPARATOR."../helpers/xlsreader.php");
ini_set("memory_limit", "128M"); 
error_reporting(E_ALL);

class DefaultController extends YBackController{
	public $strDump;
	protected $modelCG;
	protected $modelCF;
	protected $restId;
	protected $timestamp;
	protected $currentSheet;
	protected $memoryPeak;
	
	public function actionPreSync(){
		
		$model = new SyncMenu;
		$pathToFile = Yii::getPathOfAlias('webroot')."/SYNC";

		if(isset($_POST['SyncMenu'])){
			$modelCG = new CarteGoods;
			$modelCF = new CarteFolders;
			
			$model->attributes=$_POST['SyncMenu'];
			
			$model->fileUpload=CUploadedFile::getInstance($model,'fileUpload');
			CVarDumper::dump($model->fileUpload);

			$model->fileUpload->saveAs($pathToFile);
			
			$restName = Restaurant::model()->find("id={$_POST['SyncMenu']['restaurantId']}")->name;
		}
	}
	
	public function actionAdmin()
	{
		$this->memoryPeak = memory_get_usage();
		$this->timestamp = date("Y-m-d h:i:s",time());
		
		$model = new SyncMenu;
		$restList = Restaurant::model()->getRestaurantList();
		$restList[0] = Yii::t('restaurant','--нет--');
		$filename = date("mdhs").".xls";
		$pathToDir = Yii::getPathOfAlias('application.modules.syncmenu').DIRECTORY_SEPARATOR."sync";
		$pathToFile = $pathToDir.DIRECTORY_SEPARATOR.$filename;
		
		$pathToDir = $this->normalizePath($pathToDir);
		$pathToFile = $this->normalizePath($pathToFile);

		if (!is_dir($pathToDir)){
			if (mkdir($pathToDir))
				chmod("$pathToDir", 0755);
		}
		
		if(isset($_POST['SyncMenu'])){
			$this->restId = $_POST['SyncMenu']['restaurantId'];
			$this->modelCG = new CarteGoods;
			$this->modelCF = new CarteFolders;
			
			$model->attributes=$_POST['SyncMenu'];
			$model->fileup=CUploadedFile::getInstance($model,'fileup');
			
			$model->fileup->saveAs($pathToFile);
			$countSheets = $this->getCountSheet($pathToFile);

			
			for ($i=0; $i<=$countSheets-1; $i++){

				$this->currentSheet = $i;
				$data = $this->getXlsData($pathToFile,$i);

				$parentId = $this->updCarteFolder($this->modelCF,
					$data['menulabel'],
					$this->restId,
					0,
					0
				);

				$this->loopUpd($data,false,$parentId);
				
				$this->writeLog('folderId: '.'parentId'.$parentId);
				//delete old folders and goods
				$this->deleteOldRecursive($parentId);
				
			}
			//delete cart folders
			$fordel = '';
			$fordel = CarteFolders::model()->findAll("creation_date<>'{$this->timestamp}' and restaurantId={$this->restId} and parentId=0");

			foreach($fordel as $each){
				$this->writeLog('DEL FOLDER: '.$each->name,'red');
			}
			if($fordel){
				$deleted = CarteFolders::model()->deleteAll("creation_date<>'{$this->timestamp}' and restaurantId={$this->restId} and parentId=0");
			}
			
			$this->memoryPeak = round((memory_get_usage() - $this->memoryPeak)/1024/1024,3);
			
			$this->writeLog('memory: '.$this->memoryPeak.' M','red');
			$this->writeLog('memory peak: '.round((memory_get_peak_usage()/1024/1024),3).' M','red');
			$this->writeLog('server memory limit: '.ini_get("memory_limit"),'red');
			
			unlink($pathToFile);

			$this->render('sync', array(
				'model' => $model,
				'restaurantList' => $restList,
				'dump' => $this->strDump,
			));

		}else{
		$this->render('sync', array(
			'model' => $model,
			'restaurantList' => $restList,
			));
		}
	}

	protected function loopUpd($data,$folderId=false,$parentId){
		$pos = 0;
		foreach ($data as $key => $cf){
		if (isset($cf['items'])){
			if(is_array($cf['items'])){

				$parentId = $parentId;
				$folderId = $this->updCarteFolder($this->modelCF,
					trim($cf['carte']),
					$this->restId,
					$parentId,
					$pos
				);
				
				$goodsPos = 0;
				foreach($cf['items'] as $ccf){
					if (isset($ccf['items'])){
						//this next level
						$this->loopUpd(array($ccf),$ccf['carte'],$folderId);
					}else{
						//goods
						if(isset($ccf['name'])){ //fix empty
							$goodId = $this->updCarteGoods($this->modelCG,$ccf,$folderId,$goodsPos);
						}
					}
					$goodsPos++;
				}
			}
			
			//delete old goods
			$fordel = '';
			$fordel = CarteGoods::model()->findAll("creation_date<>'{$this->timestamp}' and carteFolderId=$folderId");
			foreach($fordel as $each){
				$this->writeLog('DEL: '.$each->name,'red');
			}
			if($fordel){
				$deleted = CarteGoods::model()->deleteAll("creation_date<>'{$this->timestamp}' and carteFolderId=$folderId");
			}
			$pos++;
		}
		}//end foreach
		
	}
	


	protected function updCarteFolder($model,$folderName,$restId,$parentId=0,$pos=0){
		$folderName = substr($folderName,0,500);
		//fix for next sheet
		if ($this->currentSheet>0){
			$folder = $model->find("name='{$folderName}' and parentId=$parentId and restaurantId=$restId");
		}else{
			$folder = $model->find("name='{$folderName}' and restaurantId=$restId");
		}
		
		if (empty($folder) && !empty($folderName)){
			//CREATE FOLDER
			$Nmodel = new CarteFolders;
			//set attributes
			$Nmodel['restaurantId'] = $restId;
			$Nmodel['name'] = $folderName;
			$Nmodel['parentId'] = $parentId;
			$Nmodel['url'] = '#';
			$Nmodel['position'] = $pos;
			$Nmodel['creation_date'] = $this->timestamp;
			
			if ($Nmodel->save()){
				$folderId = $Nmodel->id;
				$this->writeLog("CRT FOLDER uid:$folderId pid:$parentId | $folderName",'blue');
			}else{
				echo "<pre>ошибка при попытке записи в DB</pre>";
				foreach ($Nmodel->getErrors() as $error){
					echo $error[0]."<br/>";	
				}
			}
		}else if(!empty($folderName)){
			//UPDATE FOLDER
			//$folder['restaurantId'] = $restId;
			$folder['name'] = $folderName;
			$folder['parentId'] = $parentId;
			$folder['url'] = '#';
			$folder['position'] = $pos;
			$folder['creation_date'] = $this->timestamp;
			
			if ($folder->save()){
				//$folderId = $Nmodel->id;
				$folderId = $folder->id;
				$this->writeLog("UPD FOLDER uid:$folderId pid:$parentId | $folderName ",'green');
			}else{
				echo "<pre>ошибка при попытке записи в DB</pre>";
				foreach ($folder->getErrors() as $error){
					echo $error[0]."<br/>";	
				}
			}
			
			$folderId = $folder->id;
		}
		return $folderId;
	}
	
	protected function updCarteGoods($model,$good,$folderId,$pos){
		$dbgood = $model->find("name='{$good['name']}' and carteFolderId={$folderId}");
		//var_dump(isset($dbgood->id));
		if (empty($dbgood) && !empty($good['name'])){
			// CREATE GOODS 
			$model =  new CarteGoods;
			$model['name'] = $good['name'];
			$model['carteFolderId'] = $folderId;
			$model['description'] = isset($good['descr']) ? $good['descr'] : '-';
			$model['price'] = isset($good['price']) ? $good['price'] : 0;
			$model['amount'] = isset($good['unit']) ? $good['unit'] : '-';
			$model['enabled'] = 1;
			$model['new'] = 1;
			$model['position'] = $pos;
			$model['creation_date'] = $this->timestamp;
			
			$this->writeLog("CRT - {$model->id} {$model->name} | folder: {$folderId}","blue");
			
			if (!$model->save()){
				//var_dump($model->getErrors());
			}else{
				return $model->id;
			}
		}else{ 
			// UPDATE GOODS
			$dbgood['name'] = $good['name'];
			$dbgood['description'] = isset($good['descr']) ? $good['descr'] : '-';
			$dbgood['carteFolderId'] = $folderId;
			$dbgood['price'] = isset($good['price']) ? $good['price'] : 0;
			$dbgood['amount'] = isset($good['unit']) ? $good['unit'] : '-';
			$dbgood['enabled'] = 1;
			$dbgood['new'] = 1;
			$dbgood['position'] = $pos;
			$dbgood['creation_date'] = $this->timestamp;
			
			
			$this->writeLog("UPD - {$dbgood->id} - {$dbgood->name} | folder: {$folderId}","green");
			
			if(!$dbgood->save()){
				//var_dump($dbgood->getErrors());
			}else{
				return $dbgood->id;
			}
		}	
	}
	protected function getCountSheet($filename){
		$xls = new Spreadsheet_Excel_Reader($filename);
		$count = count($xls->sheets);
		//unset($xls);
		return $count;
	}
	protected function getXlsData($filename,$sheet){
		$xls = new Spreadsheet_Excel_Reader($filename);
		$data = array();
		$ci = 0;
		$cci = 0;
		$gi = 0;
		$toplevel = false;
		$is_item = false;
		$goods = array();
		$data = array();
		$nameCol = array(
			1 => 'name',
			2 => 'descr',
			3 => 'unit',
			4 => 'price',
			'reserve',
			'reserve',
			'reserve',
			'reserve',
		);
		
		$SheetName = $xls->boundsheets[$sheet]['name'];
		
		for ($row=1;$row<=$xls->sheets[$sheet]['numRows'];$row++){
		// get cell
			for ($col=1;$col<=$xls->sheets[$sheet]['numCols'];$col++){
			// if cells colspan this cartegood name;
				$dataShee = $xls->val($row,$col,$sheet);
				
				if (isset($xls->sheets[$sheet]['cellsInfo'][$row][$col]["colspan"])){
					//is header
					if (empty($dataShee))
						break; // skip empty cells 
					
					if (strstr($dataShee,"#")){
						// root
						$carteName = htmlspecialchars(str_replace("#",'',$dataShee));
						$is_subitem = false;
					}else{
						// sub category
						$carteName = htmlspecialchars($dataShee);
						$is_subitem = true;
					}
					$is_item = false;
				}else{
					//is goods column
					if($dataShee){ //skip empty cell
						if(isset($nameCol[$col])){
							$item[$nameCol[$col]] = isset($dataShee) ? htmlspecialchars($dataShee,ENT_QUOTES) : '';
						}
						$is_item = true;
					}
				}
				
			}//end parse columns in row
			if (!$is_item){
				if(!$is_subitem){
					$ci++;
					$data[$ci]['carte'] = $carteName;
				}else{
					$cci++;
					$data[$ci]['items'][$cci]['carte'] = $carteName;
				}
			}else{
				if(isset($item)){
					if(count($item)>0){
						//break;
						if(!$is_subitem){
							$data[$ci]['items'][] = $item;
						}else{
							$data[$ci]['items'][$cci]['items'][] = $item;
						}
						unset($item);
					}
				}
			}
		}
		$data['menulabel'] = $SheetName;
		return $data;
	}
	
	public function deleteOldRecursive($id){
		$fordel = CarteFolders::model()->findAll("creation_date<'{$this->timestamp}' and parentId=$id");
		
		if($fordel){
			foreach($fordel as $each){
				$this->writeLog('DEL: '.$each->id.' | '.$each->name, 'red');
				$this->deleteOldRecursive($each->id);
				CarteGoods::model()->deleteAll("carteFolderId={$each->id}");
				CarteFolders::model()->deleteAll("id={$each->id}");
			}
		}
	}
	
	
	public function writeLog($message,$style='gray'){
		$this->strDump.="<div class=\"{$style}\">".$message."</div>";
	}

	public function loadModel()
	{
		if ($this->_model === null)
		{
			if (isset($_GET['id']))			
				$this->_model = Page::model()->with('author', 'changeAuthor')->findbyPk($_GET['id']);
			
			if ($this->_model === null)			
				throw new YPageNotFoundException('The requested page does not exist.');
		}
		
		return $this->_model;
	}

	/**
	 * Performs the AJAX validation.
	 * @param CModel the model to be validated
	 */
	
	protected function normalizePath($path){
		return str_replace( array("\/","/"),array(DIRECTORY_SEPARATOR,DIRECTORY_SEPARATOR),$path);
	}
	
	protected function performAjaxValidation($model)
	{
		if (isset($_POST['ajax']) && $_POST['ajax'] === 'page-form')
		{
			//echo CActiveForm::validate($model);
			Yii::app()->end();
		}
	}
}