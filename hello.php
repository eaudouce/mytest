<?php
/**
 * 测试
 *
 * @auth degang.shen
 */
class Gjdairun003Controller extends Controller
{
    /**
     * @var string way url
     */
    private $flight_one_url = 'http://sale.transaero.ru/step2?departureAirportCode=%s&arrivalAirportCode=%s&adultsNum=1&childsNum=0&infantsNum=0&cabinName=touristic&departureDate=%s&returnDate=&way=one-way&send=Search&departureAirport=LON';

    /**
     * get flight info
     * 
     * @return mixed $flights
     */
    private function getFlightInfo()
    { //{{{
        $this->cur_url = 'http://sale.transaero.ru/';
        $curl = $this->curl();
        $curl->get($this->cur_url);  

        $curl = $this->curl();
        $html_info = $curl->get($this->flight_one_url);  
        $header = $curl->getHeaders();
        
        $this->cur_url = 'http://sale.transaero.ru/api/get-prices-by-days';
        $curl = $this->curl();
        $days = $curl->get($this->cur_url);
        $days = CJSON::decode($days);

        $this->cur_url = 'http://sale.transaero.ru/api/get-available-flights';
        $curl = $this->curl();
        $flights = $curl->get($this->cur_url);
        $flights = CJSON::decode($flights);
   
        return $flights;
    } //}}}

    /**
     * 网页解析,获取机票信息 
     */
    public function actionProcess()
    { //{{{
        $this->flight_one_url = sprintf($this->flight_one_url, strtoupper($this->request['dep']), 
            strtoupper($this->request['arr']), $this->request['depDate']);
        $flights = $this->getFlightInfo();
        if ($flights === false)
        {
            $this->flight_res['ret'] = false;
            $this->flight_res['status'] = 'CONNECTION_FAIL';
        }

        if ($flights['success'] == 1)
        {
            $flights_data = $flights['data']['journeys']; 
            $is_transit = $flights['data']['isTransit'];

            if ($is_transit)
            {
                //请求economy类型
                $url_array = array(
                    array('url' => 'http://sale.transaero.ru/api/get-flight-prices-by-class?cabinName=economy', 'type' => 'economy'),
                    array('url' => 'http://sale.transaero.ru/api/get-flight-prices-by-class?cabinName=business', 'type' => 'business')
                );
            }
            else
            {
                $url_array = array(
                    array('url' => 'http://sale.transaero.ru/api/get-flight-prices-by-class?cabinName=discount+10+kg', 'type' => 'discount+10+kg'),
                    array('url' => 'http://sale.transaero.ru/api/get-flight-prices-by-class?cabinName=discount+15+kg', 'type' => 'discount+15+kg'),
                    array('url' => 'http://sale.transaero.ru/api/get-flight-prices-by-class?cabinName=discount+20+kg', 'type' => 'discount+20+kg'),
                    array('url' => 'http://sale.transaero.ru/api/get-flight-prices-by-class?cabinName=discount+plus', 'type' => 'discount+plus'),
                    array('url' => 'http://sale.transaero.ru/api/get-flight-prices-by-class?cabinName=economy_full', 'type' => 'economy_full'),
                    array('url' => 'http://sale.transaero.ru/api/get-flight-prices-by-class?cabinName=economy_full_irretrievable', 'type' => 'economy_full_irretrievable'),
                    array('url' => 'http://sale.transaero.ru/api/get-flight-prices-by-class?cabinName=touristic','type' => 'touristic'),
                    array('url' => 'http://sale.transaero.ru/api/get-flight-prices-by-class?cabinName=touristic_irretrievable', 'type' => 'touristic_irretrievable'),
                    array('url' => 'http://sale.transaero.ru/api/get-flight-prices-by-class?cabinName=business', 'type' => 'business'),
                );
            }

            $prices_rph = $this->queryBaseFare($url_array);
            $prices_rph = $prices_rph['journey_rph'];

            //求最小的价格
            foreach ($prices_rph as $r_key => $r_val)
            {
                if (is_array($r_val['baseFare']))
                {
                    asort($r_val['baseFare']);
                    $keys = array_keys($r_val['baseFare']);
                    $prices_rph[$r_key]['baseFare'] = $r_val['baseFare'][$keys[0]];
                    $prices_rph[$r_key]['type'] = $r_val['type'][$keys[0]];
                }
            }

            $data = array();

            if (!empty($flights_data))
            {
                $i = 0;
                foreach ($flights_data as $key => $val) 
                {
                    $i += 1;
                    $total_price = '';
                    $tax_price = '';
                    $rph_num = $val['rph'];
                    $way_rph = $prices_rph['rph_'.$val['rph']];
                    $cabin_name = is_array($way_rph['type']) ? $way_rph['type'][0] : $way_rph['type'];
                    $this->cur_url = 'http://sale.transaero.ru/api/get-price-result-table?rph='.$rph_num.'&cabinName='.$cabin_name.'&directResponse=1';
                    $curl = $this->curl();
                    $economy_full_info = $curl->get($this->cur_url);
                    $price_list = CJSON::decode($economy_full_info);

                    $total_price = isset($price_list[0]['rate']) ? $price_list[0]['rate'] : '';
                    $tax_price = isset($price_list[0]['tax']) ? $price_list[0]['tax'] : '';

                    foreach($val['segments'] as $kseg => $segval)  
                    {
                        $data['data'][$key]['detail']['arrcity'] = $this->request['arr'];
                        $data['data'][$key]['detail']['depcity'] = $this->request['dep']; 
                        $data['data'][$key]['detail']['depdate'] = strtotime($this->request['depDate'].' 00:00:00').'000';
                        $data['data'][$key]['detail']['flightno'][] = $segval['marketingAirline'].$segval['flightNumber'];

                        $data['data'][$key]['detail']['monetaryunit'] = 'RUB';
                        $data['data'][$key]['detail']['price'] = $total_price;
                        $data['data'][$key]['detail']['status'] = 0;
                        $data['data'][$key]['detail']['tax'] = (float)$tax_price;
                        $data['data'][$key]['detail']['wrapperid'] = $this->request['wrapperid'];

                        $departure_date_time_arr = explode('T', $segval['departureDateTime']);
                        $arrival_date_time_arr = explode('T', $segval['arrivalDateTime']);
                        $flight_info = array(
                            'arrDate' => $arrival_date_time_arr[0],     
                            'arrairport' => $segval['arrivalAirport'],
                            'arrtime' => substr($arrival_date_time_arr[1], 0, 5),
                            'depDate' => $departure_date_time_arr[0],
                            'depairport' => $segval['departureAirport'],
                            'deptime' => substr($departure_date_time_arr[1], 0, 5),
                            'flightno' => $segval['marketingAirline'].$segval['flightNumber'],
                        );
                        $data['data'][$key]['info'][] = $flight_info;
                    }
                    $this->flight_res['ret'] = true;
                    $this->flight_res['status'] = 'SUCCESS';
                }
            }

        }
        else
        {
            $this->flight_res['ret'] = true;
            $this->flight_res['status'] = 'NO_RESULT';
        }

        $data['ret'] = $this->flight_res['ret'];
        $data['status'] = $this->flight_res['status'];
        isset($data['data']) && $data['data'] = array_values($data['data']);

        $this->toJson($data);
    } //}}}

    /**
     * one Way booking
     */
    public function actionBookingInfo()
    { //{{{
        $booking = $this->urlBooking();  
        exit(CJSON::encode($booking));
    } //}}}

    /**
     * booking url 
     *
     * @return string $booking
     */
    private function urlBooking()
    { //{{{
        $this->flight_one_url = sprintf($this->flight_one_url, strtoupper($this->request['dep']), 
            strtoupper($this->request['arr']), $this->request['depDate']);
        $flight_url = $this->flight_one_url;

        parse_str(substr($flight_url, strpos($flight_url, '?') + 0x01, strlen($flight_url)) ,$flight_one_arr);

        $booking = array('ret' =>true, 'data' => array('action' => substr($flight_url, 0, strpos($flight_url, '?')), 'contentType' => 'utf-8',
            'method' => 'get', 'inputs' => $flight_one_arr)); 
        return $booking;
    } //}}}

    /**
     * 组合数据
     *
     * @param array $touristic_list
     * @param string $type
     * @return array $prices_rph
     */
    private function flightPricesCabin($touristic_list, $type)
    { //{{{
        $prices_rph = array();
        if (isset($touristic_list['separateJourneysCosts']))
        {
            foreach ($touristic_list['separateJourneysCosts'] as $toukey => $touval)
            {
                //判是去程还是返程
                if (isset($touval['returnJourneyRPH']))
                {
                    $prices_rph['return_rph']['rph_'.$touval['returnJourneyRPH']] = array(
                        'baseFare' => $touval['passengersCosts'][0]['baseFare'],
                        'type' => $type
                    ); 
                }
                if (isset($touval['journeyRPH']))
                {
                    $prices_rph['journey_rph']['rph_'.$touval['journeyRPH']] = array(
                        'baseFare' => $touval['passengersCosts'][0]['baseFare'],
                        'type' => $type
                    ); 
                }
            }
        }
        return $prices_rph;
    } //}}}

    /**
     * 合并数据
     *
     * @param array $way_data
     * @param array $ret_data
     * @return array $new_data
     */
    private function merData($way_data, $ret_data)
    { //{{{
        $new_data = array();
        $i = 0;
        foreach ($way_data as $key => $val) 
        {
            foreach ($ret_data as $rkey => $rval) 
            {
                $i += 1;
                $new_data[$i] = $val;
                $new_data[$i]['ret_info'] = $rval;
            }
        }

        return $new_data;
    } //}}}

    /**
     * 处理航班详情列表
     *
     * @param array $segval
     * @return array $flight_info
     */
    private function getFlightList($segval)
    { //{{{
        $departure_date_time_arr = explode('T', $segval['departureDateTime']);
        $arrival_date_time_arr = explode('T', $segval['arrivalDateTime']);
        $flight_info = array(
            'arrDate' => $arrival_date_time_arr[0],     
            'arrairport' => $segval['arrivalAirport'],
            'arrtime' => substr($arrival_date_time_arr[1], 0, 5),
            'depDate' => $departure_date_time_arr[0],
            'depairport' => $segval['departureAirport'],
            'deptime' => substr($departure_date_time_arr[1], 0, 5),
            'flightno' => $segval['marketingAirline'].$segval['flightNumber'],
        );

        return $flight_info;
    } //}}}

   /**
    * 获取所有价格
    */
    private function queryBaseFare($url_array)
    { //{{{
        $ret = array();
        foreach ($url_array as $key => $val)
        {
            $this->cur_url = $val['url'];
            $curl = $this->curl();
            $touristic_list = $curl->get($this->cur_url);
            $touristic_list = CJSON::decode($touristic_list);
            $discount_plus_list = $this->flightPricesCabin($touristic_list, $val['type']);
            $ret = array_merge_recursive($ret, $discount_plus_list);
        }
        return $ret;
    } //}}}

}
