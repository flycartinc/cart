<?php
namespace Flycartinc\Cart;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Class Cart
 * @package StorePress\library
 */
class Cart extends Model
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
        $cart_items = Session()->get('cart_items');
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

    public static function items()
    {
        return self::getItems(true);
    }

    /**
     * @param $items
     */
    public static function setItems($items)
    {
        if (is_array($items) OR is_object($items)) {
            Session()->set('cart_items', $items);
        }
    }

    /**
     * @param $item
     * @return array
     */
    public static function add($item, $isCart = false)
    {
        $item['row_id'] = hash('md5', $item['pro_id'] . '_' . $item['var_id']);
        $cart_items = self::getItems();
        if (!empty($cart_items) and self::checkIsExist($item)) {
            if (!$isCart) {
                $cart_items[$item['row_id']]['qty'] = self::updateStock($item['row_id'], $item['qty']);
            } else {
                $cart_items[$item['row_id']] = $item;
            }
        } else {
            $cart_items[$item['row_id']] = $item;
        }
        if (empty($item)) return array();
        self::setItems($cart_items);
        return true;
    }

    /**
     * @param $row_id
     * @param $quantity
     * @return bool
     */
    public static function updateStock($row_id, $quantity)
    {
        $cart_items = self::getItems();
        if (empty($cart_items)) return false;
        $result = (int)$cart_items[$row_id]['qty'] + (int)$quantity;
        return $result;
    }

    /**
     * @param array $data
     * @return bool
     */
    public static function update_cart($data = array())
    {
        $cart_items = self::getItems();
        if (empty($cart_items)) return false;
        $cart_items[$data['row_id']][$data['field']] = $data['value'];
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
        $result = $cart_items->where($field, $value);
        if ($result and !empty($result)) {
            if ($eloquent) {
                return (object)$result;
            } else {
                return $result->toArray();
            }
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
    public static function destroy_cart()
    {
        /** Remove On Session */
        if (Session()->has('cart_items')) {
            Session()->remove('cart_items');
        }

        /** To Remove the Existing Cookie */
        if (isset($_COOKIE['cart_items'])) {
            setcookie('cart_items', '', time() - 3600, "/");
        }

        /** Removing on User Meta */
        if (is_user_logged_in()) {
            $id = get_current_user_id();
            update_user_meta($id, 'cart_items', '');
        }
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
        $isExist = $cart_items->where('product_id', $item['product_id'])->count();
        return ($isExist > 0) ? true : false;
    }

}
