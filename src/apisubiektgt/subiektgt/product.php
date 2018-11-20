<?php
namespace APISubiektGT\SubiektGT;
use COM;
use APISubiektGT\MSSql;
use APISubiektGT\Logger;
use APISubiektGT\SubiektGT\SubiektObj;
use APISubiektGT\SubiektGT;

class Product extends SubiektObj{

	protected $productGt = false;	
	protected $ean = 0;
	protected $code = '';
	protected $price;
	protected $wholesale_price = 0;
	protected $name;	
	protected $qty;	
	protected $supplier_code = '';
	protected $supplier_id = '';
	protected $time_of_delivery = 0;
	protected $id_store = 1;	
	protected $products_qtys = array();

	public function __construct($subiektGt,$productDetail = array()){		
		parent::__construct($subiektGt, $productDetail);
		$this->excludeAttr('productGt');
		
		if($this->code!='' &&  $subiektGt->Towary->Istnieje($this->code)){
			$this->productGt = $subiektGt->Towary->Wczytaj($this->code);			
			$this->is_exists = true;			
			$this->getGtObject();
		}		
	}

	protected function setGtObject(){				
		if(!$this->is_exists){
			$new_prefix = SubiektGT::getInstance()->getConfig()->getNewProductPrefix();
			if(strlen($new_prefix)>0){				
				$this->productGt->Nazwa = substr("{$new_prefix} {$this->name}",0,50);
			}else{
				$this->productGt->Nazwa = substr("{$this->name}",0,50);
			}
		}else{
			$this->productGt->Nazwa =  substr("{$this->name}",0,50);
		}
		$this->productGt->Opis = $this->name;
		$this->productGt->Symbol = substr(sprintf('%s',$this->code),0,20);
		$this->productGt->Aktywny = true;
		$this->productGt->CzasDostawy = $this->time_of_delivery;
		if($this->supplier_id != ''){
			$this->productGt->DostawcaId = $this->supplier_id;
		}
		//cena detaliczna
		if($this->productGt->Ceny->Liczba>0){
			$this->productGt->Ceny->Element(1)->Brutto = floatval($this->price);			
		}
		
		//cena hurtowa
		if($this->productGt->Ceny->Liczba>1 && $this->wholesale_price>0){
			$this->productGt->Ceny->Element(2)->Netto = floatval($this->wholesale_price);			
		}



		if(strlen($this->supplier_code)>0){
			 $this->productGt->SymbolUDostawcy = substr(sprintf('%s',$this->supplier_code),0,20);
		}
		$ean = sprintf('%d',trim($this->ean));
		if(!$this->is_exists && $ean>0){
			$this->productGt->KodyKreskowe->Dodaj($ean);
		}
		return true;
	}

	protected function getGtObject(){
		if(!$this->productGt){
			return false;
		}
		$this->gt_id = $this->productGt->Identyfikator;
		$this->name = $this->productGt->Nazwa;		
		$this->code = $this->productGt->Symbol;
		$this->time_of_delivery = $this->productGt->CzasDostawy;
		$this->supplier_id = $this->productGt->DostawcaId;
		$this->supplier_code = $this->productGt->SymbolUDostawcy;
		if($this->productGt->KodyKreskowe->Liczba>0){
			$this->ean = $this->productGt->KodyKreskowe->Element(1);
		}
		if($this->productGt->Ceny->Liczba>0){
			$prices = $this->productGt->Ceny->Element(1);
			$this->price = floatval($prices->Brutto);			
		}

		if($this->productGt->Ceny->Liczba>1){
			$prices = $this->productGt->Ceny->Element(2);
			$this->wholesale_price = floatval($prices->Netto);			
		}
		$qty = $this->getQty();
		$this->qty = intval($qty['Dostepne']);
		return true;
	}
	public function getPriceCalculations(){
		if(!$this->productGt){
			return false;
		}
		$this->setGtObject();
		$this->productGt = $this->subiektGt->Towary->Wczytaj($this->code);
		

		Logger::getInstance()->log('api','Pobrano kalkulacje cen: '.$this->productGt->Symbol,__CLASS__.'->'.__FUNCTION__,__LINE__);
	
		$priceList = $this->productGt->Zakupy;
	
		for ($i = 1, $size = $priceList->Liczba; $i<$size + 1; ++$i)
		{
			$data[$i]['nazwa'] = $priceList->Element($i)->Nazwa;
			$data[$i]['wartosc'] = (string)$priceList->Element($i)->Wartosc;
		}
		

		 return $data;
	}

	public function getListByStore(){
		$sql = "SELECT tw_Symbol as code ,Rezerwacja as resevation,Dostepne as available, Stan as on_store,  st_MagId as id_store FROM vwTowar WHERE st_MagId = ".intval($this->id_store);
		$data = MSSql::getInstance()->query($sql);
		return $data[0];	
	}

	public function getQtysByCode(){
		$qtys = array();
		foreach($this->products_qtys as $pq){
		$code = $pq['code'];
		$id_store = isset($pq['id_store'])?intval($pq['id_store']):0;
		$sql = 'SELECT tw_Id as id ,tw_Symbol as code, Rezerwacja as resevation , Dostepne as available, Stan as on_store   FROM vwTowar LEFT JOIN 
			tw_KodKreskowy ON kk_IdTowar = tw_Id 
			WHERE st_MagId = '.$id_store.' AND tw_Symbol = \''.$code.'\'';
				
			$data = MSSql::getInstance()->query($sql);
			if(!isset($data[0])){
				$qtys[$code] = 'not found';
				continue;
			}
		 	$qtys[$code]['id'] = $data[0]['id'];
		 	$qtys[$code]['code'] = $data[0]['code'];
		 	$qtys[$code]['resevation'] = intval($data[0]['resevation']);
		 	$qtys[$code]['available'] = intval($data[0]['available']);
		 	$qtys[$code]['on_store'] = intval($data[0]['on_store']);
		 	
		}
		return $qtys;
	}

	protected function getQty(){
		$sql = "SELECT TOP 1 Rezerwacja,Dostepne,Stan  FROM vwTowar WHERE tw_Id = {$this->gt_id} AND st_MagId = ".intval($this->id_store);		
		$data = MSSql::getInstance()->query($sql);
		return $data[0];
	}

	public function add(){
		$this->productGt = $this->subiektGt->TowaryManager->DodajTowar();
		$this->setGtObject();		
		$this->productGt->Zapisz();
		Logger::getInstance()->log('api','Utworzono produkt: '.$this->productGt->Symbol,__CLASS__.'->'.__FUNCTION__,__LINE__);
		return array('gt_id'=>$this->productGt->Identyfikator);
	}

	public function setProductSupplierCode($supplier_code){
		if(!$this->productGt){
			return false;
		}
		$this->productGt->SymbolUDostawcy = substr(sprintf('%s',$supplier_code),0,20);
		$this->productGt->Zapisz();
		Logger::getInstance()->log('api','Zaktualizowano kod dostawcy produktu: '.$supplier_code,__CLASS__.'->'.__FUNCTION__,__LINE__);
		return true;	
	}

	public function update(){
		if(!$this->productGt){
			return false;
		}
		$this->setGtObject();		
		$this->productGt->Zapisz();
		Logger::getInstance()->log('api','Zaktualizowano produkt: '.$this->productGt->Symbol,__CLASS__.'->'.__FUNCTION__,__LINE__);
		return $this;
	}

	public function getGt(){
		return $this->productGt;
	}
}
?>
