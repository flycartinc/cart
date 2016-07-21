<?php
namespace Flycartinc\Cart;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Corcel\User;

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

    protected static $enc_key = '!@#$%^OLKINU1234';

    /**
     * Cart constructor.
     */
    public function __construct()
    {
        $this->initCart();
    }

    /**
     * To Add Session Cart item to Database
     */
    public static function initCart()
    {
        if (!Session()->has('cart_items')) {

            /** CASE 1: User login based restore */
            /** Initially verify the User logged in or not */
            if (is_user_logged_in()) {

                /** Attempt to retrieve the User ID */
                $id = get_current_user_id();

                /** Get Corresponding User's Cart item meta */
                $content = User::find($id)->meta()->where('meta_key', 'cart_items')->get()
                    ->pluck('meta_value', 'meta_key');
                $content = $content['cart_items'];
                /** If User Meta have cart data's, then check Cookie */
                if (!$content) {

                    /** If Cookie Hold cart data's, then restore with that */
                    if (isset($_COOKIE['cart_items'])) {

                        /** Cookie were stored in Encrypted form in generally */
                        $content = json_decode(self::decrypt($_COOKIE['cart_items']), true);
                    } else {
                        $content = array();
                    }
                }

                /** CASE 2: User cookie based restore */

                /** If Cookie is Not Empty and User Not Logged In */
            } else if (!empty($_COOKIE['cart_items'])) {

                /** Get Active Cookie's Cart items */
                $content = json_decode(self::decrypt($_COOKIE['cart_items']), true);
            } else {

                $content = array();
            }

            /** Decrypt the Cart item and Update Cart Session */
            Session()->set('cart_items', $content);
        }
    }

    /**
     * To Get All items from the Session,
     *
     * @param bool $isEloquent
     * @return array|Collection|mixed
     */
    public static function getItems($isEloquent = false)
    {
        if (!Session()->has('cart_items')) self::initCart();

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
                $cart_items[$item['row_id']]['quantity'] = self::updateStock($item['row_id'], $item['quantity']);
            } else {
                $cart_items[$item['row_id']] = $item;
            }
        } else {
            $cart_items[$item['row_id']] = $item;
        }
        if (empty($item)) return array();
        self::setItems($cart_items);

        /** Updating Cart status make sure the Stability  */
        self::updateCartStatus();
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
        $result = (int)$cart_items[$row_id]['quantity'] + (int)$quantity;
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

    /**
     * To Encrypt the given data with Encryption package by Secret Key
     *
     * @param string $string Raw Data
     * @return string Encoded Data
     */
    public static function encrypt($string)
    {
        $encoder = new Encrypter(self::$enc_key);
        if (!$string) return array();
        return $encoder->encrypt($string);
    }

    /**
     * To Decrypt the given Crypt data by Secret Key
     *
     * @param string $coded_string Encoded Data
     * @return string Raw Data
     */
    public static function decrypt($coded_string)
    {
        $decode = new Encrypter(self::$enc_key);
        if (!$coded_string) return array();
        return $decode->decrypt($coded_string);
    }

    /**
     * For Updating the user status to persist the cart
     * and make it available for later access.
     */
    protected static function updateCartStatus()
    {
        /** Get Corresponding User's Cart item meta */
        $data = self::encrypt(json_encode(self::getItems()));

        if (is_user_logged_in()) {

            /** Attempt to retrieve the User ID */
            $id = get_current_user_id();

            /** Update Cart data with user's meta */
            $user = new User();
            $user = $user->find($id);
            $user->meta->cart_items = $data;
            $user->save();
        }

        /** To Set the Encoded Content to Fresh Cookie */
        setcookie('cart_items', $data, time() + (3600 * 24 * 2), "/");
    }

}
