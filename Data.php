<?php

/**
 * Description of Data
 *
 * @author fintegro
 */
class Fintegro_Parser_Helper_Data extends Mage_Core_Helper_Abstract {
	private static $MERK = array("M-Plus", "Thule");
	private static $PARSE_URL = "http://www.dakdragerexpert.nl/dakdragers/";
	private static $URL_MANUFACTURER = "?manufacturer=";
	private static $URL_MODEL = "&model=";
	private static $URL_CAR_TYPE = "&car_type=";
	private static $URL_BOUWJAAR = "&bouwjaar=";
	private static $URL_PAGE = "&p=";
	private static $firstSelect = "manufacturer_filter";
	private static $secondSelect = "model_filter";
	private static $thirdSelect = "car_type_filter";
	private static $fourthSelect = "bouwjaar_filter";
	private static $firstCategory = array();
	private static $secondCategory = array();
	private static $thirdCategory = array();
	private static $fourthCategory = array();
	private static $productIds = array();
	private $newProducts;
	private $updatedProducts;

	public function parseSite() {
		$coreHelper        = Mage::helper('fintegro_core');
		$this->newProducts = $this->updatedProducts = 0;
		$this->connectToUrl(self::$PARSE_URL, self::$firstSelect);
		$deletedCount = 0;

		return array($this->newProducts, $this->updatedProducts, $deletedCount);
	}

	private function connectToUrl($url, $selectName = null) {
		sleep(2);
		$site = $this->getContent($url);
		try {
			if ($selectName != null) {
				$finder        = new Zend_Dom_Query($site);
				$result        = $finder->query("select#$selectName");
				$selectElement = $result->current();
				$optionTags = $selectElement->getElementsByTagName('option');
				$this->getOptionValue($url, $optionTags, $selectName);
			} else {
				$finder = new Zend_Dom_Query($site);
				$links  = $finder->query(".product-item > div > a");
				foreach ($links as $link) {
					$productUrl = $link->getAttribute('href');
					$this->parseProduct($productUrl);
				}
			}
		} catch (Exception $e) {
			Mage::logException($e);
		}
	}

	function getContent($url, $post_paramtrs = false) {
		$c = curl_init();
		curl_setopt($c, CURLOPT_URL, $url);
		curl_setopt($c, CURLOPT_RETURNTRANSFER, 1);
		if ($post_paramtrs) {
			curl_setopt($c, CURLOPT_POST, true);
			curl_setopt($c, CURLOPT_POSTFIELDS, "var1=bla&" . $post_paramtrs);
		}
		curl_setopt($c, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($c, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($c, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 6.1; rv:33.0) Gecko/20100101 Firefox/33.0");
		curl_setopt($c, CURLOPT_COOKIE, 'CookieName1=Value;');
		curl_setopt($c, CURLOPT_MAXREDIRS, 10);
		$follow_allowed = (ini_get('open_basedir') || ini_get('safe_mode')) ? false : true;
		if ($follow_allowed) {
			curl_setopt($c, CURLOPT_FOLLOWLOCATION, 1);
		}
		curl_setopt($c, CURLOPT_CONNECTTIMEOUT, 9);
		curl_setopt($c, CURLOPT_REFERER, $url);
		curl_setopt($c, CURLOPT_TIMEOUT, 60);
		curl_setopt($c, CURLOPT_AUTOREFERER, true);
		curl_setopt($c, CURLOPT_ENCODING, 'gzip,deflate');
		$data   = curl_exec($c);
		$status = curl_getinfo($c);
		curl_close($c);
		preg_match('/(http(|s)):\/\/(.*?)\/(.*\/|)/si', $status['url'], $link);
		$data = preg_replace('/(src|href|action)=(\'|\")((?!(http|https|javascript:|\/\/|\/)).*?)(\'|\")/si', '$1=$2' . $link[0] . '$3$4$5', $data);
		$data = preg_replace('/(src|href|action)=(\'|\")((?!(http|https|javascript:|\/\/)).*?)(\'|\")/si', '$1=$2' . $link[1] . '://' . $link[3] . '$3$4$5', $data);
		if ($status['http_code'] == 200) {
			return $data;
		} elseif ($status['http_code'] == 301 || $status['http_code'] == 302) {
			if (! $follow_allowed) {
				if (empty($redirURL)) {
					if (! empty($status['redirect_url'])) {
						$redirURL = $status['redirect_url'];
					}
				}
				if (empty($redirURL)) {
					preg_match('/(Location:|URI:)(.*?)(\r|\n)/si', $data, $m);
					if (! empty($m[2])) {
						$redirURL = $m[2];
					}
				}
				if (empty($redirURL)) {
					preg_match('/href\=\"(.*?)\"(.*?)here\<\/a\>/si', $data, $m);
					if (! empty($m[1])) {
						$redirURL = $m[1];
					}
				}
				if (! empty($redirURL)) {
					$t = debug_backtrace();

					return call_user_func($t[0]["function"], trim($redirURL), $post_paramtrs);
				}
			}
		}

		return "ERRORCODE22 with $url!!<br/>Last status codes<b/>:" . json_encode($status) . "<br/><br/>Last data got<br/>:$data";
	}

	private function getLinksCount($finder, $url) {
		$links = $finder->query(".pager .pages li:not(.previous):not(.next)");
		$count = $links->count() / 2;
		if ($count > 0) {
			var_dump($count);
			var_dump($url);
		}
	}

	private function parseProduct($url) {
		sleep(2);
		$site   = $this->getContent($url);
		$finder = new Zend_Dom_Query($site);
		try {
			$manufacture = $this->getProductManufacture($finder);
			if (in_array($manufacture, self::$MERK)) {
				self::$productIds  = array();
				$productOptions    = $this->getProductOptions($finder);
				$productName       = $this->getProductName($finder);
				$productAttributes = $this->getProductAttributes($finder);
				$productPrice      = $this->getProductPrice($finder);
				$productDesc       = $this->getProductDesc($finder);
				$productShortDesc  = $this->getProductShortDesc($finder);
				$stock             = ($manufacture == "M-Plus");
				$images            = $this->getProductImages($finder);
				$child             = count($productOptions);

				if ($child) {
					foreach ($productOptions as $key => $option) {
						$this->updateProduct($productName . " ({$option['label']})", $productAttributes, $productPrice, $productDesc, $productShortDesc, $stock, $images, $child, $option);
					}
					$this->updateConfigurableProduct($productOptions, $productName, $productAttributes, $productPrice, $productDesc, $productShortDesc, $stock, $images);
				} else {
					$this->updateProduct($productName, $productAttributes, $productPrice, $productDesc, $productShortDesc, $stock, $images);
				}
			}
		} catch (Exception $e) {
			Mage::logException($e);
		}
	}

	private function getProductManufacture($finder) {
		$result      = $finder->query(".data-table#product-attribute-specs-table > tbody > tr > td.fabriek");
		$nameElement = $result->current();

		return $nameElement->textContent;
	}

	private function getProductName($finder) {
		$result      = $finder->query(".product-view .product-shop .product-name h1");
		$nameElement = $result->current();

		return $nameElement->textContent;
	}

	private function getProductAttributes($finder) {
		$result      = $finder->query("#product-attribute-specs-table");
		$element     = $result->current();
		$domNodeList = $element->getElementsByTagname('tr');
		$attributes  = array();
		foreach ($domNodeList as $domElement) {
			$label = $domElement->getElementsByTagname('th')->item(0)->nodeValue;
			$code  = str_replace(" ", "_", trim(strtolower($label)));
			$code  = str_replace("(", "", $code);
			$code  = str_replace(")", "", $code);
			$value = $domElement->getElementsByTagname('td')->item(0)->nodeValue;

			$attributes[ $code ] = array('value' => $value, 'label' => $label);
		}

		return $attributes;
	}

	private function getProductPrice($finder) {
		$price              = array();
		$result             = $finder->query(".product-view .price-box .old-price .price");
		$priceElement       = $result->current();
		$price['old-price'] = $this->getFloat($priceElement->textContent);

		$result                 = $finder->query(".product-view .price-box .special-price .price");
		$priceElement           = $result->current();
		$price['special-price'] = $this->getFloat($priceElement->textContent);

		return $price;
	}

	private function getProductDesc($finder) {
		$result      = $finder->query(".product-view #description .std");
		$descElement = $result->current();

		return $this->getInnerHtml($descElement);
	}

	private function getProductShortDesc($finder) {
		$result           = $finder->query(".product-view .short-description .std");
		$shortDescElement = $result->current();

		return $this->getInnerHtml($shortDescElement);
	}

	private function getProductOptions($finder) {
		$result        = $finder->query(".product-view select.product-custom-option");
		$selectElement = $result->current();
		$optionTags    = $selectElement->getElementsByTagName('option');
		$options       = array();

		foreach ($optionTags as $key => $option) {
			if ($key != 0) {
				$price     = $option->getAttribute('price');
				$text      = trim(explode("+", $option->textContent)[0]);
				$options[] = array('price' => $price, 'label' => $text);
			}
		}

		return $options;
	}

	private function getProductImages($finder) {
		$images            = array();
		$imageLinkElements = $finder->query(".product-view  .more-views li a");
		foreach ($imageLinkElements as $imageLinkElement) {
			$imageLink = $imageLinkElement->getAttribute("href");
			$imagePath = $this->loadImage($imageLink);
			array_push($images, $imagePath);
		}

		return $images;
	}

	private function loadImage($imageUrl) {
		$image_url = $imageUrl;
		$image_url = str_replace("https://", "http://", $image_url);
		$filename  = $this->getImageName($imageUrl); //give a new name, you can modify as per your requirement
		$filePath  = Mage::getBaseDir('media') . DS . 'import'; //path for temp storage folder: ./media/import/
		if (! is_dir($filePath)) {
			mkdir($filePath, 0777, true);
		}
		$filePath .= DS . $filename;

		$curl_handle = curl_init();
		curl_setopt($curl_handle, CURLOPT_URL, $image_url);
		curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 2);
		curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($curl_handle, CURLOPT_USERAGENT, 'Cirkel');
		$query = curl_exec($curl_handle);
		curl_close($curl_handle);


		file_put_contents($filePath, $query); //store the image from external url to the temp storage folder file_get_contents(trim($image_url))
		return $filePath;
	}

	private function getFloat($str) {
		if (strstr($str, ",")) {
			$str = str_replace(".", "", $str); // replace dots (thousand seps) with blancs
			$str = str_replace(",", ".", $str); // replace ',' with '.'
		}

		if (preg_match("#([0-9\.]+)#", $str, $match)) { // search for number that may contain '.'
			return $match[0];
		} else {
			return $str; // take some last chances with floatval
		}
	}

	private function getInnerHtml($node) {
		$innerHTML = '';
		$children  = $node->childNodes;
		foreach ($children as $child) {
			$innerHTML .= $child->ownerDocument->saveXML($child);
		}

		return $innerHTML;
	}

	private function trimString($string) {
		return trim($string, " \t\n\r\0\x0B");
	}

	private function getOptionValue($url, $optionTags, $selectName) {
		foreach ($optionTags as $option) {
			$value = $option->getAttribute('value');
			if ($value != 0) {
				$categoryName = $this->trimString($option->textContent);
				switch ($selectName) {
					case self::$firstSelect:
						self::$firstCategory[ $categoryName ] = null;
						self::$secondCategory                 = array();
						$this->connectToUrl($url . self::$URL_MANUFACTURER . $value, self::$secondSelect);
						self::$firstCategory[ $categoryName ] = self::$secondCategory;
						break;
					case self::$secondSelect:
						self::$secondCategory[ $categoryName ] = null;
						self::$thirdCategory                   = array();
						$this->connectToUrl($url . self::$URL_MODEL . $value, self::$thirdSelect);
						self::$secondCategory[ $categoryName ] = self::$thirdCategory;
						break;
					case self::$thirdSelect:
						self::$thirdCategory[ $categoryName ] = null;
						self::$fourthCategory                 = array();
						$this->connectToUrl($url . self::$URL_CAR_TYPE . $value, self::$fourthSelect);
						self::$thirdCategory[ $categoryName ] = self::$fourthCategory;
						break;
					case self::$fourthSelect:
						array_push(self::$fourthCategory, $categoryName);
						$this->connectToUrl($url . self::$URL_BOUWJAAR . $value);
						break;
				}
			}
		}
	}

	private function getImageName($url) {
		$url_arr = explode('/', $url);
		$ct      = count($url_arr);
		$name    = $url_arr[ $ct - 1 ];

		return $name;
	}

	private function updateConfigurableProduct($options, $productName, $productAttributes, $productPrice, $productDesc, $productShortDesc, $stock, $images) {
		$coreHelper = Mage::helper('fintegro_core');
		$product    = Mage::getModel('catalog/product')->loadByAttribute('name', $productName);
		try {
			if ($product) {
				$productId = $product->getId();
				$stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($productId);
				$stockItem->setData('use_config_manage_stock', 0);
				$stockItem->setData('manage_stock', $stock);
				$stockItem->setData('use_config_notify_stock_qty', 0);
				$stockItem->setData('is_in_stock', $stock);
				$stockItem->setData('qty', ($stock) ? 25 : 0);
				$stockItem->save();
				$this->updatedProducts ++;
				$ids           = $this->loadCategories();
				$oldCategories = $product->getCategoryIds();
				$newCatIds     = array_unique(array_merge($oldCategories, $ids), SORT_REGULAR);
				$product->setCategoryIds($newCatIds);
				$product->setSku(explode(" ", $productName)[0] . " " . $product->getEntityId());
				$product->save();
			} else {
				$product = Mage::getModel('catalog/product');
				$product->setWebsiteIds(array(1))
				        ->setAttributeSetId(4)
				        ->setTypeId('configurable')
				        ->setWeight(0.0000)
				        ->setStatus(1)//product status (1 - enabled, 2 - disabled)
				        ->setTaxClassId(0)//tax class (0 - none, 1 - default, 2 - taxable, 4 - shipping)
				        ->setVisibility(Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH)
				        ->setStockData(array(
						        'is_in_stock'                 => $stock, //Stock Availability
						        'qty'                         => ($stock) ? 25 : 0, //qty
						        'manage_stock'                => $stock,
						        'use_config_notify_stock_qty' => 0,
						        'use_config_manage_stock'     => 0
					        )
				        );//catalog and search visibility
				$product = $this->addProductData($product, $productName, $productAttributes, $productPrice, $productDesc, $productShortDesc, $images);
				$coreHelper->setFirstImageDefault($product);
				$product->save();
				$this->newProducts ++;

				$attributeCode = "color_bars";

				$attribute = Mage::getModel('eav/entity_attribute')->loadByCode(Mage_Catalog_Model_Product::ENTITY, $attributeCode);
				if ($attribute->isEmpty()) {
					$attributeLabel = "Kleur stangen";
					Mage::getModel('fintegro_parser/model')->createAttribute($attributeCode, $attributeLabel, "select");
					$attribute = Mage::getModel('eav/entity_attribute')->loadByCode(Mage_Catalog_Model_Product::ENTITY, $attributeCode);
				}

				$product->getTypeInstance()->setUsedProductAttributeIds(array($attribute->getAttributeId())); //attribute ID of attribute 'color' in my store
				$configurableAttributesData = $product->getTypeInstance()->getConfigurableAttributesAsArray();

				$configurableProductsData = array();
				foreach (self::$productIds as $key => $productId) {
					$option     = $options[ $key ];
					$valueIndex = $this->getAttributeId($attribute, $attributeCode, $option['label']);

					$productData                               = array( //['920'] = id of a simple product associated with this configurable
						'label'         => $option['label'], //attribute label
						'attribute_id'  => $attribute->getAttributeId(), //attribute ID of attribute 'color' in my store
						'value_index'   => $valueIndex, //value of 'Green' index of the attribute 'color'
						'is_percent'    => false, //fixed/percent price for this option
						'pricing_value' => $option['price'] //value for the pricing

					);
					$configurableProductsData[ $productId ]    = $productData;
					$configurableAttributesData[0]['values'][] = $productData;
				}

				$product->setConfigurableProductsData($configurableProductsData);
				$product->setConfigurableAttributesData($configurableAttributesData);
				$product->setCanSaveConfigurableAttributes(true);
				$product->setSku(explode(" ", $productName)[0] . " " . $product->getEntityId());
				$product->save();
			}
		} catch (Exception $e) {
			Mage::logException($e);
			$coreHelper->_addMessage($e->getMessage(), false);
		} finally {
			$coreHelper->deleteImages($images);
		}
	}

	private function updateProduct($productName, $productAttributes, $productPrice, $productDesc, $productShortDesc, $stock, $images, $child = false, $option = false) {
		$coreHelper = Mage::helper('fintegro_core');
		try {
			$product = Mage::getModel('catalog/product')->loadByAttribute('name', $productName);
			if ($product) {
				$productId = $product->getId();
				$stockItem = Mage::getModel('cataloginventory/stock_item')->loadByProduct($productId);
				$stockItem->setData('use_config_manage_stock', 0);
				$stockItem->setData('manage_stock', $stock);
				$stockItem->setData('use_config_notify_stock_qty', 0);
				$stockItem->setData('is_in_stock', $stock);
				$stockItem->setData('qty', ($stock) ? 25 : 0);
				$stockItem->save();
				$this->updatedProducts ++;

				$ids           = $this->loadCategories();
				$oldCategories = $product->getCategoryIds();
				$newCatIds     = array_unique(array_merge($oldCategories, $ids), SORT_REGULAR);
				$product->setCategoryIds($newCatIds);
				$product->setSku(explode(" ", $productName)[0] . " " . $product->getEntityId());
				$product->save();
			} else {
				$visibility = ($child) ? Mage_Catalog_Model_Product_Visibility::VISIBILITY_NOT_VISIBLE : Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH;
				$product    = Mage::getModel('catalog/product');
				$product->setWebsiteIds(array(1))
				        ->setAttributeSetId(4)
				        ->setTypeId('simple')
				        ->setWeight(0.0000)
				        ->setStatus(1)//product status (1 - enabled, 2 - disabled)
				        ->setTaxClassId(0)//tax class (0 - none, 1 - default, 2 - taxable, 4 - shipping)
				        ->setVisibility($visibility)
				        ->setStockData(array(
						        'is_in_stock'                 => $stock, //Stock Availability
						        'qty'                         => ($stock) ? 25 : 0, //qty
						        'manage_stock'                => $stock,
						        'use_config_notify_stock_qty' => 0,
						        'use_config_manage_stock'     => 0
					        )
				        );//catalog and search visibility
				$product = $this->addProductData($product, $productName, $productAttributes, $productPrice, $productDesc, $productShortDesc, $images, $option);
				$product->save();

				$product->setSku(explode(" ", $productName)[0] . " " . $product->getEntityId());
				$product->save();
				$this->newProducts ++;
			}
			if ($child) {
				self::$productIds[] = $product->getEntityId();
			} else {
				$coreHelper->deleteImages($images);
			}
			$coreHelper->setFirstImageDefault($product);
		} catch (Exception $e) {
			Mage::logException($e);
			$coreHelper->_addMessage($e->getMessage(), false);
		}
	}

	private function getAttributeId($attribute, $code, $newValue) {
		$isNew       = true;
		$options     = $attribute->getSource()->getAllOptions();
		$biggerValue = 0;
		foreach ($options as $key => $value) {
			if ($value['value'] > $biggerValue) {
				$biggerValue = $value['value'];
			}
			if (in_array($newValue, $value)) {
				return $value['value'];
			}
		}
		if ($isNew) {
			// making new option
			$addOptionData = array(
				"attribute_id" => $attribute->getId(),
				"value"        => array(array($newValue))
			);

			$setup = new Mage_Eav_Model_Entity_Setup('core_setup');
			$setup->addAttributeOption($addOptionData);

			// getting new id
			$attribute = Mage::getModel("eav/entity_attribute")->loadByCode("catalog_product", $code);
			$source    = $attribute->getSource();
			$options   = $source->getAllOptions();

			foreach ($options as $optionValue) {
				if ($newValue == $optionValue["label"]) {
					return $optionValue["value"];
				}
			}
		}

		return null;
	}

	private function addProductData($product, $productName, $productAttributes, $productPrice, $productDesc, $productShortDesc, $images, $option = false) {
		$product->setName($productName)//product name
		        ->setStatus(1)
		        ->setPrice($productPrice['old-price'])
		        ->setSpecialPrice($productPrice['special-price'])
		        ->setDescription($productDesc)
		        ->setShortDescription($productShortDesc);

		if ($option) {
			$code        = "color_bars";
			$attribute   = Mage::getModel('eav/entity_attribute')->loadByCode(Mage_Catalog_Model_Product::ENTITY, $code);
			$isNew       = true;
			$options     = $attribute->getSource()->getAllOptions();
			$biggerValue = 0;
			foreach ($options as $key => $value) {
				if ($value['value'] > $biggerValue) {
					$biggerValue = $value['value'];
				}
				if (in_array($option['label'], $value)) {
					$product->setData($code, $value['value']);
					$isNew = false;
					break;
				}
			}
			if ($isNew) {
				// making new option
				$addOptionData = array(
					"attribute_id" => $attribute->getId(),
					"value"        => array(array($option['label']))
				);

				$setup = new Mage_Eav_Model_Entity_Setup('core_setup');
				$setup->addAttributeOption($addOptionData);

				// getting new id
				$attribute = Mage::getModel("eav/entity_attribute")->loadByCode("catalog_product", $code);
				$source    = $attribute->getSource();
				$options   = $source->getAllOptions();

				foreach ($options as $optionValue) {
					if ($option['label'] == $optionValue["label"]) {
						$product->setData($code, $optionValue["value"]);
					}
				}
			}
		}

		foreach ($productAttributes as $code => $params) {
			$attribute = Mage::getModel('eav/entity_attribute')->loadByCode(Mage_Catalog_Model_Product::ENTITY, $code);
			if ($attribute->isEmpty()) {
				Mage::getModel('fintegro_parser/model')->createAttribute($code, $params["label"], "select");
				$attribute = Mage::getModel('eav/entity_attribute')->loadByCode(Mage_Catalog_Model_Product::ENTITY, $code);
			}
			$attributeFrontendInput = $attribute->getFrontendInput();

			switch ($attributeFrontendInput) {
				case "select":
					$isNew       = true;
					$options     = $attribute->getSource()->getAllOptions();
					$biggerValue = 0;
					foreach ($options as $key => $value) {
						if ($value['value'] > $biggerValue) {
							$biggerValue = $value['value'];
						}
						if (in_array($params['value'], $value)) {
							$product->setData($code, $value['value']);
							$isNew = false;
							break;
						}
					}
					if ($isNew) {
						// making new option
						$addOptionData = array(
							"attribute_id" => $attribute->getId(),
							"value"        => array(array($params['value']))
						);

						$setup = new Mage_Eav_Model_Entity_Setup('core_setup');
						$setup->addAttributeOption($addOptionData);

						// getting new id
						$attribute = Mage::getModel("eav/entity_attribute")->loadByCode("catalog_product", $code);
						$source    = $attribute->getSource();
						$options   = $source->getAllOptions();

						foreach ($options as $optionValue) {
							if ($params['value'] == $optionValue["label"]) {
								$product->setData($code, $optionValue["value"]);
							}
						}
					}
					break;
				case 'multiselect':
					$sourceModel = Mage::getModel('catalog/product')->getResource()->getAttribute($code)->getSource();
					$options     = $sourceModel->getAllOptions();
					$valuesText  = array_map('trim', explode(',', $params['value']));
					foreach ($valuesText as $valueText) {
						$isNew       = true;
						$biggerValue = 0;
						foreach ($options as $key => $value) {
							if ($value['value'] > $biggerValue) {
								$biggerValue = $value['value'];
							}
							$valueText  = intval($valueText);
							$valueLabel = intval($value['label']);
							if ($valueText == $valueLabel) {
								$isNew = false;
								break;
							}
						}

						if ($isNew) {
							// making new option
							$addOptionData = array(
								"attribute_id" => $attribute->getId(),
								"value"        => array(array($valueText, $valueText))
							);

							$setup = new Mage_Eav_Model_Entity_Setup('core_setup');
							$setup->addAttributeOption($addOptionData);
						}
					}

					$valuesIds = array_map(array($sourceModel, 'getOptionId'), $valuesText);
					$product->setData($code, $valuesIds);
					break;
				default:
					$product->setData($code, $params['value']);
					break;
			}
		}

		array_pop($images);
		foreach ($images as $image) {
			$product->addImageToMediaGallery($image, null, false, false);
		}

		$ids           = $this->loadCategories();
		$oldCategories = $product->getCategoryIds();
		$newCatIds     = array_unique(array_merge($oldCategories, $ids), SORT_REGULAR);
		$product->setCategoryIds($newCatIds);

		return $product;
	}

	private function loadCategories() {
		$rootId   = Mage::app()->getStore(1)->getRootCategoryId();
		$firstId  = $this->getCategory($rootId, self::$firstCategory);
		$secondId = $this->getCategory($firstId, self::$secondCategory);
		$thirdId  = $this->getCategory($secondId, self::$thirdCategory);
		$fourthId = $this->getCategory($thirdId, self::$fourthCategory, true);

		return array($firstId, $secondId, $thirdId, $fourthId);

	}

	private function getCategory($parentCategoryId, $categories, $last = false) {
		$categoryName = end($categories);
		if (! $last) {
			$categoryName = (string) key($categories);
		}

		$children = Mage::getModel('catalog/category')->load($parentCategoryId)->getChildrenCategories();
		foreach ($children as $category) {
			if ($category->getName() == $categoryName) {
				return $category->getEntityId();
			}
		}

		try {
			$category = Mage::getModel('catalog/category');
			$category->setName($categoryName);
			$category->setIsActive(1);
			$category->setStoreId(Mage::app()->getStore()->getId());
			$parentCategory = Mage::getModel('catalog/category')->load($parentCategoryId);
			$category->setPath($parentCategory->getPath());
			$category->save();

			return $category->getEntityId();
		} catch (Exception $e) {
			Mage::logException($e);
			$coreHelper = Mage::helper('fintegro_core');
			$coreHelper->_addMessage($e->getMessage(), false);
		}
	}
}
