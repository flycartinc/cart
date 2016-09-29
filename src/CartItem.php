<?php

namespace Flycartinc\Cart;


use Illuminate\Support\Collection;
use CartRabbit\Models\Product;
use CartRabbit\Models\ProductBase;
use CartRabbit\Models\ProductInterface;

class CartItem extends Collection implements CartItemInterface {

	public $product = null;

	public function __get( $key ) {
		return $this->get($key);
	}

	public function getRowId() {
		return $this->row_id;
	}

	public function getProductId() {
		return $this->product_id;
	}

	public function getVariantId() {
		return $this->var_id;
	}

	public function getQuantity() {
		return $this->get('quantity', 1);
	}

	public function getProduct() {
		if(is_null($this->product)) {

			$this->product = Product::init($this->getProductId());
			if (!isset($this->product->ID) || !$this->product->ID) {
				$this->product = new ProductBase();
			}
		}
		return $this->product;
	}

	public function setProduct(ProductInterface $product) {
		$this->product = $product;
	}

	public function isVariant() {
		return $this->is_variant;
	}

}
