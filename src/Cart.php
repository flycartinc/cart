<?php
namespace Flycartinc\Cart;

use Herbert\Framework\Models\Post;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Corcel\User;
use CartRabbit\Models\Product;

/**
 * Class Cart
 * @package CartRabbit\library
 */
class Cart extends Model
{
    /**
     * @var array of Cart Items
     */
    protected static $cart_items;

    /**
     * WARNING: If you change "$enc_key" value frequently,
     * then existing persisted content not able to restore.
     */
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
        self::updateSessionWithCookie();

        $content = Session()->get('cart_items');

        /** CASE 1: User login based restore */
        /** Initially verify the User logged in or not */
        if (is_user_logged_in()) {
            $user_content = self::getUserCart();
            $content = (!empty($user_content) ? $user_content : $content);
        }

        /** Restore the Cart to User Session */
        if (!is_array($content) and !is_object($content) and is_string($content)) {
            $content = json_decode(self::decrypt($content), true);
        }
        /** Verify the Existence of a product. */
        self::verifyProducts($content);

        /** Decrypt the Cart item and Update Cart Session */
        Session()->set('cart_items', $content);
    }

    /**
     * To Verify the Existence of Product.
     *
     * @param $content
     */
    public static function verifyProducts(&$content)
    {
        foreach ($content as $index => $product) {
            if (is_null(Product::init(array_get($product, 'product_id', 0)))) {
                unset($content[$index]);
            }
        }
    }

    /**
     *
     */
    public static function updateSessionWithCookie()
    {
        /** If User Meta have cart data's, then check Cookie */

        /** CASE 2: User cookie based restore */

        /** If Cookie Hold cart data's, then restore with that */
        if (isset($_COOKIE['cart_items'])) {

            /** Cookie were stored in Encrypted form in generally */
            $content = $_COOKIE['cart_items'];
        } else {
            $content = array();
        }
        /** Restore the Cart to User Session */
        if (!is_array($content) and !is_object($content) and is_string($content)) {
            $content = json_decode(self::decrypt($content), true);
        }

        /** Decrypt the Cart item and Update Cart Session */
        Session()->set('cart_items', $content);
    }

    /**
     * @return mixed
     */
    public static function getUserCart()
    {
        /** Attempt to retrieve the User ID */
        $id = get_current_user_id();

        /** Get Corresponding User's Cart item meta */
        $content = User::find($id)->meta()->where('meta_key', 'cart_items')->get()
            ->pluck('meta_value', 'meta_key');

        if (isset($content['cart_items'])) {
            $content = $content['cart_items'];
        }
        if ($content) {
            /** Here, User's cart content taken from userMeta */
            self::compareCart($content);
        }
        return $content;
    }

    /**
     * @param $user_cart
     * @return bool
     */
    public static function compareCart(&$user_cart)
    {
        $session_cart = Session()->get('cart_items');

        if (is_string($user_cart)) {
            $user_cart = json_decode(self::decrypt($user_cart), true);
        }

        /** If Cookie have no cart item's, then return as no difference. */
        if (empty($session_cart)) return true;
        $item_list = [];
        $result = [];
        foreach ($session_cart as $index => $item) {
            $item_list[$item['product_id']] = $item;
        }

        foreach ($user_cart as $index => $item) {
            /** If Product Already exist, */
            if (isset($item_list[$item['product_id']])) {
                /** If Different Row ID's are different, then these all are consider as two different time product. */
                if ($item_list[$item['product_id']]['row_id'] != $item['row_id']) {
                    /** Product's Quantity get summed. */
                    $item['quantity'] = $item['quantity'] + $item_list[$item['product_id']]['quantity'];
                }
            }
            $item_list[$item['product_id']] = $item;
        }
        /** For Re-Assigning the Row_id. */
        foreach ($item_list as $key => $item) {
            $result[$item['row_id']] = $item;
        }

        /** User Cart is taking as primary content. */
        $user_cart = $result;

    }

    /**
     * To Get All items from the Session,
     *
     * @param bool $isEloquent
     * @return array|Collection|mixed
     */
    public static function getItems($isEloquent = false, $withProduct = false)
    {
        $cart_items = Session()->get('cart_items');

        if ($isEloquent) {
            self::$cart_items = new CartItem();
            foreach ($cart_items as $item) {

                /** Here, the cart items get filtered to eliminate dummy or wrong items
                 * (ex. item with empty contents)
                 */
                if ($item['quantity'] !== 0 and isset($item['pro_id'])) {
                    /** "$withProduct" is true, then cart items are returned with processed product. */
                    if ($withProduct) {
                        /** Verify the Product Availability. */

                        if (is_null(Post::find($item['product_id']))) {
                            unset($cart_items[$item['row_id']]);
                            Session()->set('cart_items', $cart_items);
                        } else {
                            $product = Product::init($item['product_id']);

                            $product->processProduct();
                            if ($product !== false) {
                                $item['product'] = $product;
                                $item['product']->setRelation('meta', $item['product']->meta->pluck('meta_value', 'meta_key'));
                            }
                        }
                    }
                    self::$cart_items->push(new CartItem($item));
                }
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

            /** Updating Cart status make sure the Stability  */
            self::updateCartStatus();
        }
    }

    /**
     * @param $item
     * @return array
     */
    public static function add($item, $isCart = false)
    {
        if (Cart::verifyStock($item['product_id'], $item['quantity'])) {
            $item['row_id'] = hash('md5', $item['product_id'] . '_' . $item['var_id']);
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
            return true;
        } else {
            return false;
        }
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
        $result = $cart_items->where($field, $value)->first();
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
        return $cart_items->where('product_id', $id)->pluck('row_id');
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

        /** Clear all Session */
        Session()->remove('init_payment');
        Session()->remove('currency');
        Session()->remove('billing_address');
    }

    /**
     * @param $row_id
     * @return bool
     */
    public static function removeItem($row_id)
    {
        //If "$row_id" is empty, then return false
        if (empty($row_id) or !isset($row_id)) return false;

        $cart_items = self::getItems();

        //If "$cart_items" is empty, then return false
        if (empty($cart_items)) return false;
        unset($cart_items[$row_id[0]]);
        self::setItems($cart_items);
    }

    /**
     *
     */
    public static function removeGuest()
    {
        Session()->remove('guest_billing_address');
//        Session()->remove('guest_billing_address_verified');
        Session()->remove('guest_shipping_address');
//        Session()->remove('guest_shipping_address_verified');
        Session()->remove('guest');
        Session()->remove('guestMail');
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

    /**
     * @return mixed
     */
    public static function validateCart()
    {
        //TODO: Improve this, Perform Availability Checks
        $items = Session()->get('cart_items');
        return $items;
    }

    /**
     * To Verify the stock to make reliable product management.
     * @param $pro_id
     * @param $quantity
     * @return bool
     */
    public static function verifyStock($pro_id, $quantity)
    {
        $product = Product::init($pro_id);
        $product->processProduct();
        $stock = $product->meta->stock;
        $status = false;
        $quantity = (int)$quantity;
        //Verify Overall Stock Restriction
        if ((int)$stock->qty > $quantity) {
            //Verify Max Sale Restriction
            if ((int)$stock->min <= $quantity) {
                //Verify Min Sale Restriction
                if ((int)$stock->max >= $quantity) {
                    $status = true;
                }

            }

        }
        return $status;
    }

}
