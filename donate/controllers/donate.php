<?php
//error_reporting(E_ALL);
class Donate extends MX_Controller {
    private $fields = array();
    function __construct()
    {
        // Call the constructor of MX_Controller
        parent::__construct();
        // Make sure that we are logged in
        $this->user->userArea();

        $this->load->config('donate');
    }

    public function index()
    {
        requirePermission("view");

        $this->template->setTitle(lang("donate_title", "donate"));

        $donate_nextpay = $this->config->item('donate_nextpay');
        $donate_paygol = $this->config->item('donate_paygol');

        $user_id = $this->user->getId();

        $data = array(
            "donate_nextpay" => $donate_nextpay,
            "donate_paygol" => $donate_paygol,
            "user_id" => $user_id,
            "server_name" => $this->config->item('server_name'),
            "currency" => $this->config->item('donation_currency'),
            "currency_sign" => $this->config->item('donation_currency_sign'),
            "multiplier" => $this->config->item('donation_multiplier'),
            "multiplier_paygol" => $this->config->item('donation_multiplier_paygol'),
            "url" => pageURL
        );

        $output = $this->template->loadPage("donate.tpl", $data);

        $this->template->box("<span style='cursor:pointer;' onClick='window.location=\"" . $this->template->page_url . "ucp\"'>" . lang("ucp") . "</span> &rarr; " . lang("donate_panel", "donate"), $output, true, "modules/donate/css/donate.css", "modules/donate/js/donate.js");
    }

    public function success()
    {
        $this->user->getUserData();

        $page = $this->template->loadPage("success.tpl", array('url' => $this->template->page_url));

        $this->template->box(lang("donate_thanks", "donate"), $page, true);
    }

    public function nextpay()
    {
        $this->session->unset_userdata('amount');
        $this->session->unset_userdata('order_id');

        $donate_nextpay = $this->config->item('donate_nextpay');

        $amount = $this->input->post("amount");
        $order_id = md5(uniqid(rand(), true));

        $this->session->set_userdata('amount', $amount);
        $this->session->set_userdata('order_id', $order_id);

        $client = new SoapClient('https://api.nextpay.org/gateway/token.wsdl', array('encoding' => 'UTF-8'));
        $result = $client->PaymentRequest(
            array(
                'api_key' => $donate_nextpay["ApiKey"],
                'amount' => $amount,
                'order_id' => $order_id,
                'callback_uri' => $donate_nextpay["postback_url"]
            )
        );
        $result = $result->TokenGeneratorResult;
        if ($result->code == -1) {
            @Header('Location: https://api.nextpay.org/gateway/payment/' . $result->trans_id);
            exit;
        } else {
            echo'ERR: ' . $result->code;
            exit;
        }
    }
    public function nextpayreturnback()
    {
        $donate_nextpay = $this->config->item('donate_nextpay');

        $amount = $this->session->userdata('amount');
        $order_id = $this->session->userdata('order_id');

        $trans_id = $this->input->post("trans_id");

        $this->session->unset_userdata('amount');
        $this->session->unset_userdata('order_id');

        if (isset($trans_id) AND $order_id == $this->input->post("order_id") ) {

            $client = new SoapClient('https://api.nextpay.org/gateway/verify.wsdl', array('encoding' => 'UTF-8'));

            $result = $client->PaymentVerification(
                array(
                    'api_key' => $donate_nextpay["ApiKey"],
                    'trans_id' => $trans_id,
                    'amount' => $amount,
                    'order_id' => $order_id
                )
            );
            $result = $result->PaymentVerificationResult;

            if ($result->code == 0) {
                $this->fields['message_id'] = $trans_id;
                $this->fields['custom'] = $user_id = $this->user->getId();
                $this->fields['points'] = $this->getDpAmount($amount);
                $this->fields['timestamp'] = time();
                $this->fields['converted_price'] = $amount;
                $this->fields['currency'] = $this->config->item('donation_currency_sign');
                $this->fields['price'] = $amount;
                $this->fields['country'] = 'SE';
                $this->db->query("UPDATE `account_data` SET `dp` = `dp` + ? WHERE `id` = ?", array($this->fields['points'], $this->fields['custom']));
                $this->updateMonthlyIncome($amount);
                $this->db->insert("paygol_logs", $this->fields);
                redirect($this->template->page_url."ucp");
                exit;
                //die('success');
            } else {
                echo 'Transation failed. Status:' . $result->Status;
                exit;
            }
        } else {
            echo 'Wrong Data Sent!';
            exit;
        }

    }
    private function getDpAmount($Amount)
    {
        $config = $this->config->item('donate_nextpay');

        $points = $config['values'];
        return $points[$Amount];
    }

    private function updateMonthlyIncome($price)
    {
        $query = $this->db->query("SELECT COUNT(*) AS `total` FROM monthly_income WHERE month=?", array(date("Y-m")));

        $row = $query->result_array();

        if($row[0]['total'])
        {
            $this->db->query("UPDATE monthly_income SET amount = amount + ".round($price)." WHERE month=?", array(date("Y-m")));
        }
        else
        {
            $this->db->query("INSERT INTO monthly_income(month, amount) VALUES(?, ?)", array(date("Y-m"), round($price)));
        }
    }
}