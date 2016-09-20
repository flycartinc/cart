<?php


namespace Flycartinc\Cart;

interface CartItemInterface {


	public function getRowId();

	public function getProductId();

	public function getVariantId();

	public function getQuantity();

	public function getProduct();


}