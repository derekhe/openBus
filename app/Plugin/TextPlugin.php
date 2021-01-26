<?php
/**
 * Created by PhpStorm.
 * User: zhoutianliang
 * Date: 2017/5/4
 * Time: 10:51
 */

namespace App\Plugin;


use App\Exception\LineException;
use App\Services\AMapService;
use Psr\Container\ContainerInterface;
use Redis;
use Shrimp\Event\ResponseEvent;
use Shrimp\Response\NewsResponse;

class TextPlugin extends Plugin
{
    private array $defaultSplit = [
        '-', '_', '?', '|',
        '$', '#', '@', '&',
        '%', '~', '/', '%',
        '^', '*', '=', '+',
        '.', ' ', ','
    ];

    private string $defaultCityCode = '330100';

    /**
     * @var AMapService
     */
    private AMapService $amapService;

    /**
     * TextPlugin constructor.
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);

        $this->amapService = new AMapService($container);
    }

    /**
     * @param $message
     * @return array
     */
    private function splitContent($message): array
    {
        foreach ($this->defaultSplit as $value) {
            if (strpos($message, $value) !== false) {
                return explode($value, $message);
            }
        }
        return [$message];
    }

    /**
     * @param ResponseEvent $response
     */
    public function __invoke(ResponseEvent $response)
    {
        // TODO: Implement __invoke() method.
        $receiveData = trim((string) $response->getAttribute("Content"));
        if ($receiveData == "小程序") {
            $response->setResponse($this->getOpenMiniappString($this->config['miniapp']['appid']));
            return ;
        }
        if ($receiveData == "?" || $receiveData == "help" || $receiveData == "帮助") {
            $response->setResponse(
                new NewsResponse($response->getMessageSource(), $this->articles)
            );
            return ;
        }
        $content = $this->splitContent($receiveData);
        $line = false;
        if (is_numeric($content[0])) {
            $line = true;
        }
        if ($line == false && preg_match('/[a-z]?[0-9]+([路|线|号线]+)?/is', $content[0])) {
            $line = true;
        }
        if ($line == false && count($content) < 2) {
            $response->setResponse(
                new NewsResponse($response->getMessageSource(), $this->articles)
            );
            return ;
        }
        $responseMessage = null;
        $openId = (string) $response->getAttribute("FromUserName");
        try {
            if ($line) {
                $responseMessage = $this->getLineInfo(
                    $openId,
                    $content[0],
                    isset($content[1]) ? $content[1] : ""
                );
            } else {
                $responseMessage = $this->getDirectionTransit(
                    $openId,
                    $content[0],
                    $content[1],
                    isset($content[2]) ? $content[2] : ""
                );
            }
        } catch (\Exception $e) {
            $response->setResponse(
                new NewsResponse($response->getMessageSource(), $this->articles)
            );
            return ;
        }
        $response->setResponse($responseMessage);
    }

    /**
     * @param string $openId
     * @param string $lineName
     * @param string $region
     * @return string
     * @throws LineException
     */
    private function getLineInfo(string $openId, string $lineName, string $region = ""): string
    {

        $city = $this->queryCityCode($region, $openId, $lineName);
        $parameters = [
            'keywords' => $lineName,
            'city' => $city,
            'citylimit' => "true",
            'extensions' => "all",
            "output" => "json",
            "offset" => 4,
        ];
        $response = $this->amapService->lineNameSearch($parameters);
        if ($response->getStatusCode() != 200) {
            return "服务发生错误请稍后再试";
        }
        $content = $response->getBody()->getContents();
        $poiLine = json_decode($content, true);
        if ($poiLine['status'] != "1") {
            $this->container->get('logger')->info($content);
            throw new LineException("查询出错，未找到符合条件的线路信息");
        }
        if (count($poiLine['buslines']) < 1) {
            return "未找到符合条件的线路信息";
        }
        preg_match('/[0-9]+/is', $lineName, $matches);
        $lineNumber = 0;
        if (isset($matches[0])) {
            $lineNumber = (int) $matches[0];
        }
        $lines = [];
        foreach ($poiLine['buslines'] as $key => $busline) {
            if (count($lines) >= 2) {
                break;
            }
            if (($lineNumber && mb_strpos($busline['name'], $lineNumber) !== false) || ($lineNumber == 0 && $key < 2)) {
                $lines[] = $busline;
            }
        }
        if (empty($lines)) {
            $lines = array_splice($poiLine['buslines'], 0, 2);
        }
        $message = "";
        $appId = $this->config['miniapp']['appid'];
        foreach ($lines as $line) {
            $message .= '线路: ' . $this->getOpenMiniappLineString($appId, $line['id'], $line['name']) . "\n";
            if ($line['start_time']) {
                $message .= '时间: ' .
                    substr($line['start_time'], 0, 2) . ':' . substr($line['start_time'], 2) .
                    '-' .
                    substr($line['end_time'], 0, 2) . ':' . substr($line['end_time'], 2) .
                    "\n";
            }
            if ($line['total_price']) {
                $message .= '票价: ' . $line['total_price'] . "元\n";
            }
            $stations = array_map(function ($item) {
                return $item['name'];
            }, $line['busstops']);
            $message .= '站点: ' . implode(' -> ', $stations) . "\n\n";
        }
        return $message;
    }

    /**
     * @param string $openId
     * @param string $startPoint
     * @param string $endPoint
     * @param string $region
     * @return string
     * @throws LineException
     */
    private function getDirectionTransit(string $openId, string $startPoint, string $endPoint, string $region = ""): string
    {

        $parameters['keywords'] = $endPoint;
        $city = $this->queryCityCode($region, $openId, $startPoint . $endPoint);
        $startResponse = $this->queryKeywords($startPoint, $city);
        $endResponse =  $this->queryKeywords($endPoint, $city);
        if (empty($startResponse) || empty($endResponse)) {
            return "服务发生错误请稍后再试";
        }
        $startLocation = $startResponse['pois'][0]['location'];
        $endLocation = $endResponse['pois'][0]['location'];
        $transitResponse = $this->amapService->transitIntegrated([
            'origin' => $startLocation,
            'destination' => $endLocation,
            'strategy' => 0,
            'nightflag' => 0,
            'city' => $city,
            'cityd' => '',
            'output' => 'json',
        ]);
        if ($transitResponse->getStatusCode() != 200) {
            return "服务发生错误请稍后再试";
        }
        $r = json_decode($transitResponse->getBody()->getContents(), true);
        if ($r['status'] != '1') {
            throw new LineException("请求结果发生错误");
        }
        if (empty($r['route']['transits'])) {
            throw new LineException("不能规化线路");
        }
        $route = $r['route']['transits'][0];
        $message = "";
        foreach ($route['segments'] as $step => $segment) {
            if ($segment['walking']) {
                $count = count($segment['walking']['steps']);
                $lastStep = $segment['walking']['steps'][$count - 1];
                $message .= "步行" . $segment['walking']['distance']. '米' .
                    (is_string($lastStep['assistant_action']) ? $lastStep['assistant_action'] : "") . "\n";
            }
            if ($segment['bus'] && $segment['bus']['buslines']) {
                $busLines = $segment['bus']['buslines'];
                foreach ($busLines as $lineStep => $line) {
                    $label = "乘坐";
                    if ($lineStep > 0) {
                        $label = '换乘';
                    }
                    $message .= "{$label}[" . $line['name'] . "] -> [" . $line['arrival_stop']['name'] . "]下车\n";
                }
            }
        }
        $message = rtrim($message, "\n") . "到达终点\n\n";
        if ($route['distance']) {
            $distance = round(($route['distance'] / 1000), 1) . "公里";
            $message .= "全程:" . $distance . "\n";
        }
        if ($route['duration']) {
            $message .= "预计耗时:" . $this->formatTime($route['duration']) . "\n";
        }
        if ($route['walking_distance']) {
            $walking = $route['walking_distance'] >= 1000 ?
                round(($route['walking_distance'] / 1000), 1) . "公里"
                : $route['walking_distance'] . "米";
            $message .= "步行:" . $walking . "\n";
        }
        if ($route['cost']) {
            $message .= "车票费用:" . $route['cost'] . "元";
        }
        return $message;
    }

    /**
     * @param int $duration
     * @return string
     */
    private function formatTime(int $duration)
    {
        $hour = $min = 0;
        if ($duration >= 3600) {
            $hour = floor($duration / 3600);
            $min = floor($duration % 3600 / 60);
        } else {
            $min = floor($duration / 60);
        }
        return ($hour ? $hour . "小时" : "") . ($min > 0 ? $min . "分钟" : "");
    }

    /**
     * @param string $keywords
     * @param string $city
     * @return array
     */
    private function queryKeywords(string $keywords, string $city)
    {
        $parameters = [
            'keywords' => $keywords,
            'city' => $city,
            'citylimit' => "true",
            'children' => 0,
            'offset' => 4,
            'page' => 1,
            'extensions' => 'all',
            'antiCrab' => 'true',
            'type_' => 'KEYWORD',
        ];
        $response = $this->amapService->keywordSearch($parameters);
        if ($response->getStatusCode() != 200) {
            return [];
        }
        $r = json_decode($response->getBody()->getContents(), true);
        if ($r['status'] != '1' || (int) $r['count'] < 1) {
            return [];
        }
        return $r;
    }


    /**
     * @param string|null $region
     * @param string $openId
     * @param string $name
     * @return string
     */
    private function queryCityCode(?string $region, string $openId, string $name): string
    {
        if (empty($region)) {
            /**
             * @var $redis Redis
             */
            $redis = $this->container->get('cache');
            if (($cityCode = $redis->hGet($openId, 'adcode'))) {
                return $cityCode;
            }
            return $this->findCityByName($name);
        }
        return $this->findCityByName($region);

    }

    /**
     * @param string $name
     * @return string
     */
    private function findCityByName(string $name)
    {
        /**
         * @var $redis Redis
         */
        $redis = $this->container->get('cache');
        $cities = $redis->get('cities');
        if (empty($cities)) {
            return $this->defaultCityCode;
        }
        $cities = json_decode($cities, true);
        foreach ($cities as $item) {
            if (mb_strpos($name, $item['name']) !== false) {
                return $item['adcode'];
            }
        }
        return $this->defaultCityCode;
    }

    /**
     * @param string $appId
     * @return string
     */
    private function getOpenMiniappString(string $appId): string
    {
        return sprintf(
            '<a data-miniprogram-appid="%s" data-miniprogram-path="pages/index/index" href="/">%s</a>',
            $appId,
            "点击打开小程序",
        );
    }

    /**
     * @param string $appId
     * @param string $lineId
     * @param string $lineName
     * @return string
     */
    private function getOpenMiniappLineString(string $appId, string $lineId, string $lineName): string
    {
        return sprintf(
            '<a data-miniprogram-appid="%s" data-miniprogram-path="pages/line/line?lineid=%s" href="/">%s</a>',
            $appId,
            $lineId,
            $lineName
        );
    }
}
