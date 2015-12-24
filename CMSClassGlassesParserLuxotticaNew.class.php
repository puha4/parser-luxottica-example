<?php

class CMSClassGlassesParserLuxotticaNew extends CMSClassGlassesParser
{
    const SIZE_ONE_INDEX = 0;
    const SIZE_TWO_INDEX = 1;
    const SIZE_THREE_INDEX = 4;
    const TABLE_HEADER_INDEX = 0;
    const MAX_RECURSIVE_DEPTH = 5;
    const MAX_VARIATIONS_IN_CART = 10;

    const URL_BASE = "https://my.luxottica.com";
    const URL_LOGIN = "https://my.luxottica.com/webapp/wcs/stores/servlet/Logon";
    const URL_BRAND = "https://my.luxottica.com/webapp/wcs/stores/servlet/SearchDisplay?sType=SimpleSearch&facet=%s&urlRequestType=Base&catalogId=10001&pageView=image&showResultsPage=true&beginIndex=0&langId=-1&searchType=1000&storeId=10001";
    const URL_BRANDS = "https://my.luxottica.com/webapp/wcs/stores/servlet/AdvancedSearchView?storeId=10001&urlRequestType=Base&langId=-1&catalogId=10001";
    const CLEAR_CART_URL = "https://my.luxottica.com/webapp/wcs/stores/servlet/AjaxOrderProcessServiceOrderCancel";
    const ADD_TO_CART_URL = "https://my.luxottica.com/webapp/wcs/stores/servlet/AjaxOrderChangeServiceItemAdd";
    const GET_CART_PARAM_URL = "https://my.luxottica.com/webapp/wcs/stores/servlet/ShowSplitOrderByAvailability?catalogId=10001&langId=-1&storeId=10001";
    const CUT_SYMBOL = '0';

    const COUNTRY_USA = "usa";
    const COUNTRY_ITALY = "italy";
    const COUNTRY_UNKNOWN = "unknown";

    const FLAG_IN_STOCK = 1;
    const FLAG_OUT_OF_STOCK = 0;

    private $accountData = array();
    private $syncedBrandsIds = array();

    /**
     * CMSClassGlassesParserLuxottica constructor.
     * @param array $syncedBrandsIds
     * @param array $accountData
     */
    public function __construct(array $accountData, array $syncedBrandsIds = array())
    {
        $this->accountData = $accountData;
        $this->syncedBrandsIds = $syncedBrandsIds;
    }

    /**
     * @return int
     */
    public function getProviderId()
    {
        return CMSLogicProvider::LUXOTTICA;
    }

    /**
     * @throws CMSException
     */
    public function doLogin()
    {
        echo "Do login\n";

        $http = $this->getHttp();

        $post = array(
            "URL" => "TopCategories1",
            "URL" => "OrderItemMove?page=account&URL=OrderCalculate%3FURL%3DLogonForm",
            "catalogId" => 10001,
            "errorViewName" => "AjaxOrderItemDisplayView",
            "langId" => -1,
            "logonId" => $this->accountData['userName'],
            "logonPassword" => $this->accountData['userPassword'],
            "myAcctMain" => 1,
            "reLogonURL" => "LogonForm",
            "storeId" => 10001,
        );

        if (!$http->doPost(self::URL_LOGIN, $post)) {
            throw new CMSException();
        }
    }

    /**
     * @throws CMSException
     */
    private function doReLogin()
    {
        $this->resetHttp();
        echo "\n===sleep 3 seconds and do relogin.\n";
        sleep(3);
        $this->doLogin();
    }

    /**
     * @param string $contents
     * @return bool
     */
    public function isLoggedIn($contents)
    {
        return strpos($contents, '<li class="logout"') !== false;
    }

    /**
     * Синхронизация брендов
     */
    public function doSyncBrands()
    {
        echo "\nYou logged like - ". $this->accountData['userName'] ."\n";
        $http = $this->getHttp();

        $brands = array();
        $coded = array();

        if(!$http->doGet(self::URL_BRANDS)) {
            echo "GET URL BRAND FAIL!\n";
            return;
        }

        $content = $http->getContents();
        $dom = str_get_html($content);

        $brandUrlDom = $dom->find('.brand-checkbox  input');

        foreach($brandUrlDom as $brand) {
            $code = trim(urldecode($brand->attr['facetvalue']));
            $name = trim($brand->attr['name']);

            $brands[$code] = array(
                'name' => $name,
                'code' => $code,
            );
        }

        $dom->clear();

        $myBrands = CMSLogicBrand::getInstance()->getAll($this->getProvider());

        foreach($myBrands as $b) {
            if ($b instanceof CMSTableBrand && $b->getCode()) {
                $coded[$b->getCode()] = $b;
            }
        }

        foreach ($brands as $code => $info) {
            if (!isset($coded[$code])) {
                echo "--New ". $info['name'] ." ". $info['code'] ."\n";
                CMSLogicBrand::getInstance()->create($this->getProvider(), $info['name'], $info['code'], '');
            } else {
                echo "--Old ". $info['name'] ." ". $info['code'] ."\n";
                $oldBrand = $coded[$code];
                $oldBrand->setTitle($info['name']);
                $oldBrand->save();
            }
        }
    }

    /**
     * Синхронизация товаров
     */
    public function doSyncItems()
    {
        $brands = CMSLogicBrand::getInstance()->getAll($this->getProvider());
        $brandUrl = CMSPluginUrl::parse(self::URL_BRAND);

        foreach($brands as $brand) {
            if(!($brand instanceof CMSTableBrand)) {
                throw new Exception("Brand mast be an instance of CMSTableBrand!");
            }

            if ($brand->getValid()) {
                echo get_class($this), ': syncing items of brand: [', $brand->getId(), '] ', $brand->getTitle(), "\n";
            } else {
                echo get_class($this), ': SKIP! syncing items of Disabled brand: [', $brand->getId(), '] ', $brand->getTitle(), "\n";
                continue;
            }

            if(in_array($brand->getId(), $this->syncedBrandsIds)) {
                echo "--Brand ". $brand->getTitle() ."[". $brand->getId() ."] already synced!!\n";
                continue;
            }

            // if(!in_array($brand->getId(), array(87))) {
            //     continue;
            // }

            $countItemsDom = $this->getBrandItemsCountDom($brandUrl, $brand->getCode());

            if (!count($countItemsDom)) {
                echo "--No one model for brand. Continue.\n";
                continue;
            }

            // Сбрасываем is_valid для моделей бренда - флаг наличия модели у провайдера
            $this->resetModelByBrand($brand);

            // Сбрасываем сток для бренда
            $this->resetStockByBrand($brand);

            $this->syncedBrandsIds[] = $brand->getId();

            $countItems = $this->getCountItemsNumberFromDom($countItemsDom);

            echo "\nitems count - ". $countItems ."\n";

            $allBrandItemsDom = $this->getBrandItemsDom($brandUrl, $countItems);

            echo "----Item links found: ", count($allBrandItemsDom), "\n";

            $this->syncCategoryProducts($allBrandItemsDom, $brand);

            $this->doReLogin();
        }
    }

    /**
     * @return array
     */
    public function getSyncedBrandsIds()
    {
        return $this->syncedBrandsIds;
    }

    /**
     * Возвращает dom обьект с информацией о количестве товаров бренда
     * @param CMSPluginUrl $brandUrl
     * @param string $brandCode
     */
    private function getBrandItemsCountDom(CMSPluginUrl $brandUrl, $brandCode)
    {
        $http = $this->getHttp();

        $brandUrl->setParam('facet', $brandCode);

        if(!$http->doGet($brandUrl)) {
            echo "GET URL BRAND FAIL!\n";
            return;
        }

        $content = $http->getContents();
        $dom = str_get_html($content);

        $countItemsDom = $dom->find('span.items-number');

        return $countItemsDom;
    }

    /**
     * Возвращает число товаров
     * @param simplehtmldom $countItemsDom
     * @return int
     */
    private function getCountItemsNumberFromDom($countItemsDom) {
        $countItemsStr = $countItemsDom[0]->innertext();

        preg_match_all('/\d+/', trim($countItemsStr), $matches);
        $countItems = current($matches)[0];

        return $countItems;
    }

    /**
     * Возвращает массив dom обьектов товаров бренда
     * @param CMSPluginUrl $brandUrl
     * @param int $countItems
     */
    private function getBrandItemsDom(CMSPluginUrl $brandUrl, $countItems)
    {
        $http = $this->getHttp();

        $brandUrl->addParam('pageSize', $countItems);

        echo "--brand url - ". $brandUrl ."\n";

        if(!$http->doGet($brandUrl)) {
            echo "GET URL BRAND FAIL!\n";
            return;
        }

        $content = $http->getContents();
        $dom = str_get_html($content);

        $allBrandItemsDom = $dom->find('.item');

        return $allBrandItemsDom;
    }

    /**
     * @param $itemsDom array simplehtmldom
     * @param $brand CMSTableBrand
     */
    private function syncCategoryProducts($itemsDom , CMSTableBrand $brand)
    {
        foreach($itemsDom as $key => $itemBlockDom) {
            $itemLinkDom = current($itemBlockDom->find('a'));

            $itemHref = CMSPluginUrl::parse(html_entity_decode(trim($itemLinkDom->attr['href'])));

            $this->parseItem($itemHref, $brand);
        }
    }

    /**
     * Парсинг 1 товара
     * @param CMSPluginUrl $itemUrl
     * @param CMSTableBrand $brand
     * @return bool|void
     */
    private function parseItem(CMSPluginUrl $itemUrl, CMSTableBrand $brand)
    {
        $http = $this->getHttp();

        $itemExternalId = $itemUrl->getParamValue('productId');

        echo "-----Parse item by:\n -------{$itemUrl} \n";

        if(!$http->doGet($itemUrl)) {
            echo "GET URL Item FAIL!\n";
            return;
        }

        $content = $http->getContents();
        // пока есть два варианта ошибок на этом этапе
        // 1 - в ответ приходит пустая строка
        // 2 - страница с текстом ошибки (предположительно закончилась сессия)
        if(!$content) {
            echo "Error. Content is empty.\n";
            $this->doReLogin();
            $this->parseItem($itemUrl, $brand);
            return;
        }

        if($this->isError($content)) {
            echo "Error. May be session end.\n";
            $this->doReLogin();
            $this->parseItem($itemUrl, $brand);
            return;
        }

        echo "\n-------Clear cart.\n";
        $this->clearCart();

        echo "\n-------Get item main params.\n";
        $itemParams = $this->getItemTitleAndCategoryParams($content);

        $variations = $this->getVariations($content);

        // определяем тип очков
        $typeItem = $this->getItemType($itemParams['categoryType']);

        foreach($variations as $key => $variation) {

            $upcCode = $this->getUpcCode($itemParams['title'], $variation['colorCode'], $variation['sizes']['one']);

            echo "\n";
            echo "----------brand         - {$brand->getTitle()}\n";
            echo "----------model_name    - {$itemParams['title']}\n";
            echo "----------external_id   - {$itemExternalId}\n";
            echo "----------color_title   - {$variation['color']}\n";
            echo "----------color_code    - {$variation['colorCode']}\n";
            echo "----------size 1        - {$variation['sizes']['one']}\n";
            echo "----------size 2        - {$variation['sizes']['two']}\n";
            echo "----------size 3        - {$variation['sizes']['three']}\n";
            echo "----------image         - {$variation['img']}\n";
            echo "----------price         - {$variation['price']}\n";
            echo "----------type          - {$itemParams['categoryType']}\n";
            echo "----------upc           - {$upcCode}\n";
            echo "----------stock         - {$variation['stock']}\n\n";
            echo "----------country       - {$variation['country']}\n";
            echo "----------cartStock     - {$variation['cartStock']}\n";
            echo "--------------------------------------------\n";
            $imgFile = $this->getImgFile($variation['img']);
            // continue;

            // создаем обьект модели и синхронизируем
            $item = new CMSClassGlassesParserItem();
            $item->setBrand($brand);
            $item->setTitle($itemParams['title']);
            $item->setExternalId($itemExternalId);
            $item->setColor($variation['color']);
            $item->setColorCode($variation['colorCode']);
            $item->setSize($variation['sizes']['one']);
            $item->setSize2($variation['sizes']['two']);
            $item->setSize3($variation['sizes']['three']);
            $item->setPrice($variation['price']);
            $item->setType($typeItem);
            $item->setStockCount($variation['cartStock']);
            $item->setIsValid(1);

            if($variation['country'] == self::COUNTRY_ITALY) {
                $item->setSellFrom(1);
            }

            if($imgFile) {
                $item->setImgFile($imgFile->getFile());
            }

            if($upcCode && $upcCode != ''){
                $item->setUpc($upcCode);
            }

            $result[] = $item;
        }

        echo "\n=============================================================================================\n";
        $this->syncingResult($result);
        echo "\n=============================================================================================\n";
    }

    /**
     * @param $content string
     * @return bool
     */
    private function isError($content)
    {
        return stripos($content, 'The system has encountered a problem and we are working to fix it') !== false;
    }

    /**
     * Очищение корзины
     */
    private function clearCart()
    {
        $post = array('0'=> '5');

        $this->getHttp()->doPost(self::CLEAR_CART_URL, $post);
    }

    /**
     * @param string $typeString
     * @return CMSTableGlassesItemType
     */
    private function getItemType($typeString)
    {
        // определяем тип очков
        if(stripos($typeString, "SUN") !== false) {
            $typeItem = CMSLogicGlassesItemType::getInstance()->getSun();
        } else {
            $typeItem = CMSLogicGlassesItemType::getInstance()->getEye();
        }

        return $typeItem;
    }

    /**
     * @param array $result
     */
    private function syncingResult($result)
    {
        foreach($result as $res) {
           $res->sync();
        }
    }

    /**
     * @param string $imgUrl
     * @return CMSTableGlassesFileCache
     */
    private function getImgFile($imgUrl) {
        $imgFile = CMSLogicGlassesFileCache::getInstance()->getOneLuxottica($imgUrl, true, clone $this->getHttp());

        return $imgFile;
    }

    /**
     * @param string $itemTitle
     * @return string
     */
    private function getUpcCode($itemTitle, $colorCode, $sizeOne) {
        if($itemTitle[0] == self::CUT_SYMBOL) {
            $itemTitle = mb_substr($itemTitle, 1);
        }

        $itemTitleVariantOne = preg_replace('/ +/', ' ', $itemTitle);
        $itemTitleVariantTwo = str_replace(' ', '', $itemTitleVariantOne);
        $itemTitleVariantThree = str_replace(' ', '0', $itemTitleVariantOne);
        $itemTitleVariantFour = str_replace(' ', ' 0', $itemTitleVariantOne);

        $query = "SELECT * FROM  amz_upc
                    WHERE provider_id = :provider_id
                    AND  (`model` LIKE  :item_title_variant_one
                            OR `model` LIKE  :item_title_variant_two
                            OR `model` LIKE  :item_title_variant_three
                            OR `model` LIKE  :item_title_variant_four)
                    AND `color` LIKE :color_code
                    AND `size` = :size_one";
        $q = CMSPluginDb::getInstance()->getQuery($query);
        $q->setInt('provider_id', $this->getProviderId());
        $q->setText('item_title_variant_one', '%'.$itemTitleVariantOne.'%');
        $q->setText('item_title_variant_two', '%'.$itemTitleVariantTwo.'%');
        $q->setText('item_title_variant_three', '%'.$itemTitleVariantThree.'%');
        $q->setText('item_title_variant_four', '%'.$itemTitleVariantFour.'%');
        $q->setText('color_code', '%'.$colorCode.'%');
        $q->setText('size_one', $sizeOne);
        $data = $q->execute();

        $upcData = $data->getData();

        if(empty($upcData)) {
            return false;
        }

        return current($upcData)['upc'];
    }

    /**
     * @param $itemContent string
     * @return array
     */
    private function getItemTitleAndCategoryParams($itemContent)
    {
        $dom = str_get_html($itemContent);

        $titleDom = current($dom->find('td.info h1'));

        $title = trim(str_replace('&nbsp;', ' ', strip_tags($titleDom->innertext())));
        $title = preg_replace('/[0]*([a-z A-Z]+)[0]*([\d]+)/', '$1 $2', $title);

        $categoryTypeDom = current($dom->find(".col-left p span"));
        $categoryType = strtoupper(trim($categoryTypeDom->innertext()));

        return array(
            'title' => $title,
            'categoryType' => $categoryType,
        );
    }

    private function getVariations($itemContent)
    {
        $variations = array();
        $orderItemsIds = array();
        $variationsParamFromCart = array();

        $dom = str_get_html($itemContent);

        $allSizes = $this->getAllSizes($itemContent);

        $allVariationsDom = $dom->find('tr.sortable-element');

        $variationsCount = 1;

        foreach($allVariationsDom as $key => $variationDom) {
            $colorCodeDom = current($variationDom->find(".info-product .model-code"));
            $colorCode = trim($colorCodeDom->innertext());

            $colorDom = current($variationDom->find(".info-product .model-name"));
            $color = trim($colorDom->innertext());

            $noOrderMessageDom = $variationDom->find(".no-order-message");

            if(count($noOrderMessageDom)) {
                echo "-------Variation cannot be ordered - ". $color ." ". $colorCode ."\n";
                continue;
            }

            $priceDom = current($variationDom->find(".price .number"));
            $price = trim(str_replace(array("$", "&#36;"), '', $priceDom->innertext()));

            $imgDom = current($variationDom->find("a.fancyzoom"));
            $img = self::URL_BASE . trim($imgDom->attr['href']);

            $sizesAndStocksDom = $variationDom->find('.quantity-cb li .size-cont');

            foreach($sizesAndStocksDom as $key => $sizeAndStockBlockDom) {
                $sizeDom = current($sizeAndStockBlockDom->find('label'));

                preg_match_all('/\d+/', trim($sizeDom->innertext()), $matches);

                $oneSize = current($matches)[0];
                $sizes = $allSizes[$oneSize];

                preg_match_all('/\d+/', trim($sizeDom->attr['for']), $matches);
                $itemIdentifier = current($matches)[0];

                $orderData = $this->addToCartAndGetOrderData($itemIdentifier);

                $orderItemsIds[] = $orderData['orderItemId'];

                if($variationsCount % self::MAX_VARIATIONS_IN_CART == 0) {
                    echo "-------Count variations % 10 get data from cart and cart clear.\n";
                    $variationsParamFromCart = $variationsParamFromCart + $this->getVariationsParamFromCart($orderItemsIds);
                    $orderItemsIds = array();
                }

                $stock = count($sizeAndStockBlockDom->find('.product-availability .available')) ? 1 : 0;

                $variations[$orderData['orderItemId']] = array(
                    'colorCode' => $colorCode,
                    'color' => $color,
                    'price' => $price,
                    'img' => $img,
                    'sizes' => $sizes,
                    'stock' => $stock,
                );

                $variationsCount++;
            }

        }

        // не кратно 10 значит в корзине что то осталось
        if(!empty($orderItemsIds)) {
            echo "-------Get data from cart and cart clear.\n";
            $variationsParamFromCart = $variationsParamFromCart + $this->getVariationsParamFromCart($orderItemsIds);
        }

        $variations = $this->checkVariationParam($variations, $variationsParamFromCart);

        echo "-------Count variations ". count($variations) .". Variations from cart ". count($variationsParamFromCart) .".\n";

        return $variations;
    }

    /**
     * Возвращает все возможные размеры со страницы товара
     * @param $itemContent string
     * @return array
     */
    private function getAllSizes($itemContent)
    {
        $dom = str_get_html($itemContent);

        $allSizesTableDom = $dom->find(".specifications tbody tr");
        unset($allSizesTableDom[self::TABLE_HEADER_INDEX]);

        foreach($allSizesTableDom as $key => $sizesLineDom) {
            $sizesDom = $sizesLineDom->find('td');

            $sizeOne = isset($sizesDom[self::SIZE_ONE_INDEX]) ? round(trim($sizesDom[self::SIZE_ONE_INDEX]->innertext())) : 0;
            $sizeTwo = isset($sizesDom[self::SIZE_TWO_INDEX]) ? round(trim($sizesDom[self::SIZE_TWO_INDEX]->innertext())) : 0;
            $sizeThree = isset($sizesDom[self::SIZE_THREE_INDEX]) ? round(trim($sizesDom[self::SIZE_THREE_INDEX]->innertext())) : 0;

            $sizes[$sizeOne] = array(
                "one" => $sizeOne,
                "two" => $sizeTwo,
                "three" => $sizeThree,
            );
        }

        return $sizes;
    }

    /**
     * Добавляет один товар в корзину и возвращает уникальный код
     * @param $itemIdentifier int
     * @return array
     */
    private function addToCartAndGetOrderData($itemIdentifier, $recursiveDepth = 0)
    {
        // контроль рекурсии
        if($this->isMaxRecursiveDepth($recursiveDepth)) {
            echo "isMaxRecursiveDepth";
            return array('orderItemId' => '', 'orderId' => '');
        }

        $http = $this->getHttp();

        $post = array(
            "storeId" => "10001",
            "catalogId" => "10001",
            "langId" => "-1",
            "orderId" => ".",
            "calculationUsage" => "-1,-2,-5,-6,-7",
            "catEntryId_1" => $itemIdentifier,
            "quantity_1" => "1",
            "customerRefs" => "",
            "requesttype" => "ajax",
        );

        $http->doPost(self::ADD_TO_CART_URL, $post);
        $content = $http->getContents(false);

        $orderDataJson = str_replace(array('/*', '*/'), '' , $content);
        $orderData = json_decode($orderDataJson, true);

        if(empty($orderData)) {
            echo "Empty. Fail add to cart. Sleep 3 seconds \n";
            sleep(3);
            $this->addToCartAndGetOrderData($itemIdentifier, $recursiveDepth+1);
        }

        if(!isset($orderData['orderItemId'])) {
            echo "False. Fail add to cart. Sleep 3 seconds \n";
            sleep(3);
            $orderData = $this->addToCartAndGetOrderData($itemIdentifier, $recursiveDepth+1);
        }

        // с корзины значения походят как подэлемнты, например $orderData['orderItemId'][0] = 111
        // если сработала рекурсия то с функции вернется
        return array(
            'orderItemId' => is_array($orderData['orderItemId']) ? current($orderData['orderItemId']) : $orderData['orderItemId'],
            'orderId' => is_array($orderData['orderId']) ? current($orderData['orderId']) : $orderData['orderId'],
        );
    }

    /**
     * @return bool
     */
    private function isMaxRecursiveDepth($recursiveDepth)
    {
        if($recursiveDepth == self::MAX_RECURSIVE_DEPTH) {
            return true;
        }

        return false;
    }

    /**
     * Посылает запрос в корзину с кодами товаров и в ответ для каждого получаем сток и страну
     * @param $orderItemsIds array
     * @return array
     */
    private function getVariationsParamFromCart($orderItemsIds)
    {
        $http = $this->getHttp();

        $post = array(
            'orderItems[]' => $orderItemsIds,
        );

        $http->doJsonPost(self::GET_CART_PARAM_URL, $this->arr2request($post));
        $content = $http->getContents(false);

        $variationsParamsJson = str_replace(array('/*', '*/'), '' , $content);
        $variationsParams = json_decode($variationsParamsJson, true);

        $this->clearCart();

        return $variationsParams;
    }

    /**
     * Преобразовываем массив к строке запроса
     * @param $postArr array
     * @return string
     */
    public function arr2request($postArr)
    {
        $postParams = array();

        foreach($postArr as $key => $valuesArr) {
            foreach($valuesArr as $value) {
                $postParams[] = rawurlencode($key) . '=' . rawurlencode($value);
            }
        }

        return join('&', $postParams);
    }

    /**
     * Сравнение данных вариаций с данными в корзине
     * @param $variations array
     * @param $variationsParamFromCart array
     * @return array
     */
    private function checkVariationParam($variations, $variationsParamFromCart)
    {
        foreach($variations as $cartKey => $variation) {
            $paramCountry = current($variationsParamFromCart[$cartKey])['wh'];

            if($paramCountry == 0) {
                $stock = self::FLAG_OUT_OF_STOCK;
                $country = self::COUNTRY_UNKNOWN;
            } elseif($paramCountry == 1) {
                $stock = self::FLAG_IN_STOCK;
                $country = self::COUNTRY_USA;
            } elseif($paramCountry == 2) {
                $stock = self::FLAG_IN_STOCK;
                $country = self::COUNTRY_ITALY;
            } elseif($paramCountry > 2) {
                $stock = self::FLAG_OUT_OF_STOCK;
                $country = self::COUNTRY_ITALY;
            } else {
                $stock = self::FLAG_OUT_OF_STOCK;
                $country = self::COUNTRY_UNKNOWN;
            }

            $variations[$cartKey]['country'] = $country;
            $variations[$cartKey]['cartStock'] = $stock;
        }

        return $variations;
    }
}