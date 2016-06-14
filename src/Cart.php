<?php
namespace StorePress\library;

use Illuminate\Database\Eloquent\Collection;
use StorePress\Helper\Util;

/**
 * Class Cart
 * @package StorePress\library
 */
class Cart
{

    /**
     * @var array of Cart Items
     */
    protected static $cart_items;

    /**
     * Cart constructor.
     */
    public function __construct()
    {
        //
    }

    /**
     * To Get All items from the Session,
     *
     * @param bool $isEloquent
     * @return array|Collection|mixed
     */
    public static function getItems($isEloquent = false)
    {
        if (Session()->has('cart_items')) {
            $cart_items = Session()->get('cart_items');

        } elseif (!empty($_COOKIE['cart_items'])) {
            $cart_items = json_decode(Util::decrypt($_COOKIE['cart_items']), true);
        } else {
            return array();
        }

        if ($isEloquent) {
            self::$cart_items = new Collection();
            foreach ($cart_items as $item) {
                self::$cart_items->push(collect($item));
            }
            return self::$cart_items;
        } else {
            return $cart_items;
        }
    }

    /**
     * Method to get the cart items as eloquent collections
     */

    public static function items() {
        return self::getItems(true);
    }

    /**
     * @param $items
     */
    public static function setItems($items)
    {
        if (is_array($items) OR is_object($items)) {
            Session()->set('cart_items', $items);
            /** To Remove the Existing Cookie */
            //setcookie('cart_items', '', -3600);

            $items = Util::encrypt(json_encode($items));
            /** To Set the Encoded Content to Fresh Cookie */
            setcookie('cart_items', $items, time() + (3600 * 24 * 2), "/");
            dd(Util::decrypt($_COOKIE['cart_items']));
        }
    }

    /**
     * @param $item
     */
    public static function add($item)
    {
//        foreach($items as $item )
        $cart_items = self::getItems();
        if (!empty($cart_items)) {
            if (self::checkIsExist($item)) self::updateStock($item['id'], $item['quantity']);
        }

        //TODO : Set the "row_id" based on [Pro_id + variant_id + attributes...]

        $item['row_id'] = hash('md5', $item['id']);
        $cart_items[$item['id']] = $item;
        self::setItems($cart_items);
    }

    /**
     * @param $id
     * @param $quantity
     * @return bool
     */
    public static function updateStock($id, $quantity)
    {
        $cart_items = self::getItems();
        if (empty($cart_items)) return false;
        $cart_items[$id]['quantity'] = (int)$cart_items[$id]['quantity'] + $quantity;
        self::setItems($cart_items);
    }

    /**
     * @param array $data
     * @return bool
     */
    public static function update($data = array())
    {
        $cart_items = self::getItems();
        if (empty($cart_items)) return false;
        $cart_items[$data['id']][$data['field']] = $data['value'];
        self::setItems($cart_items);
    }

    /**
     * @param $field
     * @param $value
     * @param bool $eloquent
     * @return array|bool|mixed|object
     */
    public static function search($field, $value, $eloquent = false)
    {
        if (empty($field) OR empty($value)) return array();
        $cart_items = self::getItems(true);
        if (empty($cart_items)) return false;
        $result = $cart_items->where($field, $value)->first();
        if ($eloquent) {
            return (object)$result;
        } else {
            return $result;
        }

    }

    /**
     * @param $id
     * @return bool|\Illuminate\Support\Collection
     */
    public static function getRowID($id)
    {
        if (empty($id)) return false;

        $cart_items = self::getItems(true);
        if (empty($cart_items)) return false;

        return $cart_items->where('id', $id)->pluck('row_id');
    }

    /**
     *
     */
    public static function destroy()
    {
        Session()->remove('cart_items');
    }

    /**
     * @param $id
     * @return bool
     */
    public static function removeItem($id)
    {
        $cart_items = self::getItems();
        if (empty($cart_items)) return false;
        unset($cart_items[$id]);
        self::setItems($cart_items);
    }

    /**
     * @param $item
     * @return bool
     */
    public static function checkIsExist($item)
    {
        $cart_items = self::getItems(true);
        if (empty($cart_items)) return false;
        $isExist = $cart_items->where('id', $item['id'])->count();
        return ($isExist > 0) ? true : false;
    }

}