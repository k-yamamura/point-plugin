<?php


namespace Plugin\Point\Helper\PointCalculateHelper;

use Doctrine\ORM\EntityNotFoundException;
use Plugin\Point\Entity\PointInfo;

/**
 * ポイント計算サービスクラス
 * Class PointCalculateHelper
 * @package Plugin\Point\Helper\PointCalculateHelper
 */
class PointCalculateHelper
{
    /** @var \Eccube\Application */
    protected $app;
    /** @var \Plugin\Point\Repository\PointInfoRepository */
    protected $pointInfo;
    /** @var  \Eccube\Entity\ */
    protected $entities;
    /** @var */
    protected $products;
    /** @var */
    protected $basicRate;
    /** @var */
    protected $addPoint;
    /** @var */
    protected $productRates;
    /** @var */
    protected $usePoint;

    /**
     * PointCalculateHelper constructor.
     * @param \Eccube\Application $app
     */
    public function __construct(\Eccube\Application $app)
    {
        $this->app = $app;
        // ポイント情報基本設定取得
        $this->pointInfo = $this->app['eccube.plugin.point.repository.pointinfo']->getLastInsertData();

        if(empty($this->pointInfo)){
            return false;
        }
        // ポイント換算値
        $this->basicRate = $this->pointInfo->getPlgBasicPointRate();
        $this->entities = array();
        // 使用ポイントをセッションから取得
        $this->usePoint = 0;
    }

    /**
     * 計算に必要なエンティティを追加
     * @param $name
     * @param $entity
     */
    public function addEntity($name, $entity)
    {
        $this->entities[$name] = $entity;
    }

    /**
     * 保持エンティティを返却
     * @param $name
     * @return array|bool|\Eccube\Entity\
     */
    public function getEntity($name)
    {
        if ($this->hasEntities($name)) {
            return $this->entities[$name];
        }

        return false;
    }

    /**
     * キーをもとに該当エンティティを削除
     * @param $name
     * @return bool
     */
    public function removeEntity($name)
    {
        if ($this->hasEntities($name)) {
            unset($this->entities[$name]);

            return true;
        }

        return false;
    }

    /**
     * 保持エンティティを確認
     * @param $name
     * @return bool
     */
    public function hasEntities($name)
    {
        if (isset($this->entities[$name])) {
            return true;
        }

        return false;
    }

    /**
     * 利用ポイントの設定
     * @param $usePoint
     * @return bool
     */
    public function setUsePoint($usePoint)
    {
        // 引数の判定
        if (empty($usePoint) && $usePoint != 0) {
            return false;
        }

        $this->usePoint = $usePoint;
    }

    /**
     * ポイント計算時端数を設定に基づき計算返却
     * @param $value
     * @return bool|float
     */
    public function getRoundValue($value)
    {
        // ポイント基本設定オブジェクトの有無を確認
        if (empty($this->pointInfo)) {
            return false;
        }

        $roundType = $this->pointInfo->getPlgRoundType();

        // 切り上げ
        if ($roundType == PointInfo::POINT_ROUND_CEIL) {
            return ceil($value);
        }

        // 四捨五入
        if ($roundType == PointInfo::POINT_ROUND_ROUND) {
            return round($value, 0);
        }

        // 切り捨て
        if ($roundType == PointInfo::POINT_ROUND_FLOOR) {
            return floor($value);
        }
    }

    /**
     * 受注詳細情報の配列を返却
     * @return array|bool
     */
    protected function getOrderDetail()
    {
        // 必要エンティティを判定
        if (!$this->hasEntities('Order')) {
            return false;
        }

        // 全商品取得
        $products = array();
        foreach ($this->entities['Order']->getOrderDetails() as $key => $val) {
            $products[$val->getId()] = $val;
        }

        // 商品がない場合は処理をキャンセル
        if (count($products) < 1) {
            return false;
        }

        return $products;
    }

    /**
     * 利用ポイントが保有ポイント以内に収まっているか計算
     * @return bool
     */
    protected function isInRangeCustomerPoint()
    {
        // 必要エンティティを判定
        if (!$this->hasEntities('Customer')) {
            return false;
        }

        // 現在保有ポイント
        $customer_id = $this->entities['Customer']->getId();
        $point = $this->app['eccube.plugin.point.repository.pointcustomer']->getLastPointById($customer_id);

        // 使用ポイントが保有ポイント内か判定
        if ($point < $this->usePoint) {
            return false;
        }

        return true;
    }

    /**
     * 仮付与ポイントを返却
     *  - 会員IDをもとに返却
     * @return bool
     */
    public function getProvisionalAddPoint()
    {
        // 必要エンティティを判定
        if (!$this->hasEntities('Customer')) {
            return false;
        }

        $customer_id = $this->entities['Customer']->getId();
        $provisionalPoint = $this->app['eccube.plugin.point.repository.point']->getAllProvisionalAddPoint($customer_id);

        if (!empty($provisionalPoint)) {
            return $provisionalPoint;
        }

        return false;
    }

    /**
     * 仮付与ポイントを返却
     *  - オーダー情報をもとに返却
     * @return bool
     */
    public function getProvisionalAddPointByOrder()
    {
        // 必要エンティティを判定
        if (!$this->hasEntities('Customer')) {
            return false;
        }
        if (!$this->hasEntities('Order')) {
            return false;
        }

        $order = $this->entities['Order'];
        $provisionalPoint = $this->app['eccube.plugin.point.repository.point']->getProvisionalAddPointByOrder($order);


        if (!empty($provisionalPoint)) {
            return $provisionalPoint;
        }

        return false;
    }

    /**
     * カート情報をもとに付与ポイントを返却
     * @return bool|int
     */
    public function getAddPointByCart()
    {
        // カートエンティティチェック
        if (empty($this->entities['Cart'])) {
            return false;
        }

        // 商品毎のポイント付与率を取得
        $productClasses = array();
        $cartObjects = array();
        foreach ($this->entities['Cart']->getCartItems() as $cart) {
            $productClasses[] = $cart->getObject();     // 商品毎ポイント付与率取得用
            $cartObjects[] = $cart;                     // 購入数を判定するためのカートオブジェジェクト
        }

        // 商品毎のポイント付与率取得
        $productRates = $this->app['eccube.plugin.point.repository.pointproductrate']->getPointProductRateByEntity(
            $productClasses
        );

        // 付与率の設定がされていない場合
        if (count($productRates) < 1) {
            $productRates = false;
        }

        // 商品毎のポイント付与率セット
        $this->productRates = $productRates;

        // 取得ポイント付与率商品ID配列を取得
        if ($this->productRates) {
            $productKeys = array_keys($this->productRates);
        }

        // 商品詳細ごとの購入金額にレートをかける
        // レート計算後個数をかける
        foreach ($cartObjects as $node) {
            $rate = 1;
            // 商品毎ポイント付与率が設定されていない場合
            $rate = $this->basicRate / 100;
            if ($this->productRates) {
                if (in_array($node->getObject()->getProduct()->getId(), $productKeys)) {
                    // 商品ごとポイント付与率が設定されている場合
                    $rate = $this->productRates[$node->getObject()->getProduct()->getId()] / 100;
                }
            }
            $this->addPoint += (integer)$this->getRoundValue(
                (($node->getObject()->getPrice02() * $rate) * $node->getQuantity())
            );
        }

        // 減算処理の場合減算値を返却
        if ($this->isSubtraction() && !empty($this->usePoint)) {
            return $this->getSubtractionCalculate();
        }

        return $this->addPoint;
    }

    /**
     * 受注情報をもとに付与ポイントを返却
     * @return bool|int
     */
    public function getAddPointByOrder()
    {
        // 必要エンティティを判定
        $this->addPoint = 0;
        if (!$this->hasEntities('Order')) {
            return false;
        }

        // 商品詳細情報ををオーダーから取得
        $this->products = $this->getOrderDetail();

        if (!$this->products) {
            // 商品詳細がなければ処理終了
            return;
        }

        // 商品ごとのポイント付与率を取得
        $productRates = $this->app['eccube.plugin.point.repository.pointproductrate']->getPointProductRateByEntity(
            $this->products
        );

        // 付与率の設定がされていない場合
        if (count($productRates) < 1) {
            $productRates = false;
        }

        // 商品ごとのポイント付与率セット
        $this->productRates = $productRates;

        // 取得ポイント付与率商品ID配列を取得
        if ($this->productRates) {
            $productKeys = array_keys($this->productRates);
        }

        // 商品詳細ごとの購入金額にレートをかける
        // レート計算後個数をかける
        foreach ($this->products as $node) {
            $rate = 1;
            // 商品毎ポイント付与率が設定されていない場合
            $rate = $this->basicRate / 100;
            if ($this->productRates) {
                if (in_array($node->getProduct()->getId(), $productKeys)) {
                    // 商品ごとポイント付与率が設定されている場合
                    $rate = $this->productRates[$node->getProduct()->getId()] / 100;
                }
            }
            $this->addPoint += (integer)$this->getRoundValue(
                (($node->getProductClass()->getPrice02() * $rate) * $node->getQuantity())
            );
        }

        // 減算処理の場合減算値を返却
        if ($this->isSubtraction() && !empty($this->usePoint)) {
            return $this->getSubtractionCalculate();
        }

        return $this->addPoint;
    }

    /**
     * 商品情報から付与ポイントを返却
     * @return array|bool
     */
    public function getAddPointByProduct()
    {
        // 必要エンティティを判定
        if (!$this->hasEntities('Product')) {
            return false;
        }

        // 商品毎のレートが設定されているか確認
        $pointRate = $this->app['eccube.plugin.point.repository.pointproductrate']->getLastPointProductRateById(
            $this->entities['Product']->getId()
        );
        // サイト全体でのポイント設定
        $basicPointRate = $this->pointInfo->getPlgBasicPointRate();

        // 基本付与率の設定判定
        if (empty($basicPointRate)) {
            return false;
        }

        // 商品毎の付与率あればそちらを優先
        // なければサイト設定ポイントを利用
        $calculateRate = $basicPointRate;
        if (!empty($pointRate)) {
            $calculateRate = $pointRate;
        }

        // 金額の取得
        $min_price = $this->entities['Product']->getPrice02Min();
        $max_price = $this->entities['Product']->getPrice02Max();

        // 返却値生成
        $rate = array();
        $rate['min'] = (integer)$this->getRoundValue($min_price * ((integer)$calculateRate / 100));
        $rate['max'] = (integer)$this->getRoundValue($max_price * ((integer)$calculateRate / 100));

        return $rate;
    }

    /**
     * ポイント機能基本情報から計算方法を取得し判定
     * @return bool
     */
    protected function isSubtraction()
    {
        // 基本情報が設定されているか確認
        if (empty($this->pointInfo)) {
            return false;
        }

        // 計算方法の判定
        if ($this->pointInfo->getPlgCalculationType() === PointInfo::POINT_CALCULATE_SUBTRACTION) {
            return true;
        }

        return false;
    }

    /**
     * 利用ポイント減算処理
     * @return bool|int
     */
    protected function getSubtractionCalculate()
    {
        // 基本情報が設定されているか確認
        if (is_null($this->pointInfo->getPlgCalculationType())) {
            return false;
        }

        // 減算値計算
        if (!isset($this->usePoint) || empty($this->usePoint)) {
            return false;
        }

        $conversionRate = $this->pointInfo->getPlgPointConversionRate();
        $rate = ($this->basicRate / 100) + 1;
        $usePointAddRate = (integer)$this->getRoundValue(($this->usePoint * $rate) * $conversionRate);

        $this->addPoint = (($this->addPoint - $usePointAddRate) < 0) ? 0 : ($this->addPoint - $usePointAddRate);

        return $this->addPoint;
    }

    /**
     * 保有ポイントを返却
     * @return bool
     */
    public function getPoint()
    {
        // 必要エンティティを判定
        if (!$this->hasEntities('Customer')) {
            return false;
        }

        $customer_id = $this->entities['Customer']->getId();
        $point = $this->app['eccube.plugin.point.repository.pointcustomer']->getLastPointById($customer_id);

        return $point;
    }

    /**
     * ポイント基本機能設定から換算後ポイントを返却
     * @return bool|float
     */
    public function getConversionPoint()
    {
        // 必要エンティティを判定
        if (!$this->hasEntities('Order')) {
            return false;
        }

        // 利用ポイントの確認
        if ($this->usePoint != 0 && empty($this->usePoint)) {
            return false;
        }

        // ポイント基本設定の確認
        if (empty($this->pointInfo)) {
            return false;
        }

        // 基本換金値の取得
        $pointRate = $this->pointInfo->getPlgBasicPointRate();

        return $this->getRoundValue($this->usePoint * $pointRate);
    }

    /**
     * 受注情報と、利用ポイント・換算値から値引き額を計算し、
     * 受注情報の更新を行う
     * @param $lastUsePoint
     * @return bool
     */
    public function setDiscount($lastUsePoint)
    {
        // 必要エンティティを判定
        if (!$this->hasEntities('Order')) {
            return false;
        }

        // 利用ポイントの確認
        if ($this->usePoint != 0 && empty($this->usePoint)) {
            return false;
        }

        // ポイント基本設定の確認
        if (empty($this->pointInfo)) {
            return false;
        }

        // 受注情報に保存されている最終保存の値引き額を取得
        $currDiscount = $this->entities['Order']->getDiscount();

        // 値引き額と利用ポイント換算値を比較→相違があればポイント利用分相殺後利用ポイントセット
        $useDiscount = $this->getConversionPoint();

        $diff = $currDiscount - ($lastUsePoint * $this->pointInfo->getPlgBasicPointRate());

        if ($diff >= 0) {
            if ((integer)$currDiscount != (integer)$useDiscount) {
                $mergeDiscount = $diff + $useDiscount;
                if ($mergeDiscount >= 0) {
                    $this->entities['Order']->setDiscount(abs($mergeDiscount));

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 値引きを差し引いた合計金額から利用ポイント換算金額を差し引き
     * マイナス値が発生しないか判定
     *  - マイナス値が発生した際は、ポイントをキャンセル
     * @return bool
     * @throws EntityNotFoundException
     */
    public function calculateTotalDiscountOnChangeConditions()
    {
        // 必要エンティティを判定
        if (!$this->hasEntities('Order')) {
            throw new EntityNotFoundException();
        }
        if (!$this->hasEntities('Customer')) {
            throw new EntityNotFoundException();
        }
        // ポイント基本設定の確認
        if (empty($this->pointInfo)) {
            throw new EntityNotFoundException();
        }

        $order = $this->entities['Order'];
        $customer = $this->entities['Customer'];

        // 最終保存仮利用ポイントがあるかどうかの判定
        $usePoint = 0;
        $lastPreUsePoint = 0;
        $lastPreUsePoint = $this->app['eccube.plugin.point.repository.point']->getLastPreUsePoint($order);
        if (!empty($lastPreUsePoint)) {
            $usePoint = $lastPreUsePoint;
        }

        // 値引きを除いた支払い合計を取得
        $totalPrice = $order->getTotalPrice();
        $discount = $order->getDiscount();
        $totalAmount = $totalPrice + $discount;

        // 最終ポイント利用額を算出
        $pointDiscount = (int)$this->getRoundValue($usePoint * $this->pointInfo->getPlgPointConversionRate());

        // 現在値引き額とポイント値引き額を比較し、大きい値を計算対象とする
        $calcDiscount = 0;
        $isDiffFlg = false;
        if ($discount > $pointDiscount) {
            $calcDiscount = $discount;
            $isDiffFlg = true;
        } elseif ($discount == $pointDiscount) {
            $calcDiscount = $discount;
        } else {
            $calcDiscount = $pointDiscount;
        }

        // 総合計金額がマイナスになるかどうかを判定
        if (($totalAmount - $calcDiscount) >= 0) {
            return false;
        }

        // 上記マイナスであれば、ポイントキャンセル処理
        // 現在値引きが利用ポイントより大きい場合
        if ($isDiffFlg) {
            $discount = $discount - $pointDiscount;
        } elseif ($discount == $pointDiscount) {
            $discount = 0;
        } else {
            $discount = $pointDiscount;
        }

        // 利用ポイント差し引き値引き額をセット
        $order->setDiscount($discount);

        // 利用ポイント打ち消し
        if (!empty($lastPreUsePoint)) {
            $this->app['eccube.plugin.point.history.service']->addEntity($order);
            $this->app['eccube.plugin.point.history.service']->addEntity($customer);
            $this->app['eccube.plugin.point.history.service']->savePreUsePoint(abs($usePoint));
        }
        // キャンセルのために「0」でログテーブルを更新
        $this->app['eccube.plugin.point.history.service']->addEntity($order);
        $this->app['eccube.plugin.point.history.service']->addEntity($customer);
        $this->app['eccube.plugin.point.history.service']->savePreUsePoint(0);

        // 現在ポイントを履歴から計算
        $calculateCurrentPoint = $this->app['eccube.plugin.point.repository.point']->getCalculateCurrentPointByCustomerId(
            $order->getCustomer()->getId()
        );

        // 会員ポイント更新
        $this->app['eccube.plugin.point.repository.pointcustomer']->savePoint(
            $calculateCurrentPoint,
            $customer
        );

        // 利用ポイント打ち消しf後の受注情報更新
        $newOrder = $this->app['eccube.service.shopping']->calculatePrice($order);

        $this->app['orm.em']->persist($newOrder);
        $this->app['orm.em']->flush();

        return true;
    }
}
