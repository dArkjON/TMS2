<?php

class Payments extends Model {
	public function getUserData($userId) {
		$user = Db::queryOne('SELECT `id_user`,`fakturoid_id`,`first_name`,`last_name`,`telephone`,`address`,`ic`,`company`,`active`,`email`,`name`,`tariffCZE`,`tariffENG`,`invoicing_start_date`
                              FROM `users`
                              JOIN `tariffs` ON `tariffs`.`id_tariff` = `users`.`user_tariff`
                              JOIN `places` ON `places`.`id` = `tariffs`.`place_id`
                              WHERE `id_user` = ?', [$userId]);
		$tariff = Db::queryOne('SELECT `id_tariff`, `priceCZK`,`tariffCZE`,`tariffENG`
                                FROM `users`
                                JOIN `tariffs` ON `users`.`user_tariff` = `tariffs`.`id_tariff`
                                JOIN `places` ON `place_id` = `places`.`id`
                                WHERE  `id_user` = ?', [$userId]);
		$payments = Db::queryAll('SELECT `id_payment`,`bitcoinpay_payment_id`,`id_payer`,`payed_price_BTC`,`payment_first_date`,`status`,`tariff_id`,`price_CZK`,`invoice_fakturoid_id`
                                  FROM `payments` WHERE `id_payer` = ?
                                  ORDER BY `payment_first_date` DESC', [$userId]);
		//add extras for each payment
		foreach ($payments as &$p)
			$p['extras'] = Db::queryAll('SELECT `id_extra`, `description`, `priceCZK`, `vat`
										 FROM `extras` WHERE `payment_id` = ?', [$p['id_payment']]);
		
		return [
			'user' => $user,
			'tariff' => $tariff,
			'payments' => $payments
		];
	}
	
	public function enhanceUserPayments($payments, $lang) {
		foreach ($payments as &$p) {
			//translation for statuses
			$p['status'] = $this->translatePaymentStatus($p['status'], $lang);
			//guessing BTC price
			if (empty($p['payed_price_BTC']))
				$p['payed_price_BTC'] = round($p['price_CZK'] / $this->getExchangeRate(), 5);
			//and price for extras
			$ratio = $p['payed_price_BTC'] / $p['price_CZK'];
			foreach ($p['extras'] as &$e) {
				$e['priceBTC'] = round($ratio * $e['priceCZK'], 5);
			}
		}
		
		return $payments;
	}
	
	public function getUsersIds() {
		$dbResult = Db::queryAll('SELECT `id_user` FROM `users` WHERE `active` = 1', []);
		$result = [];
		foreach ($dbResult as $r)
			$result[] = $r['id_user'];
		
		return $result;
	}
	
	public function actualizePayments($payments) {
		$bitcoinPay = new Bitcoinpay();
		$messages = [];
		
		foreach ($payments as $payment) {
			$paymentId = $payment['id_payment'];
			$bitcoinpayId = $payment['bitcoinpay_payment_id'];
			$fakturoidId = $payment['invoice_fakturoid_id'];
			
			if (empty($payment['status']) || $payment['status'] == 'unpaid') {
				$data['status'] = 'unpaid';
				$data['price'] = null;
			}
			else {
				$data = $bitcoinPay->getTransactionDetails($bitcoinpayId);
				//invalid response
				if (empty($data)) {
					$messages[] = [
						's' => 'info',
						'cs' => 'Nepovedlo se nám spojit se se serverem bitcoinpay.com - některé platby můžou být neaktualizované',
						'en' => 'We failed at connecting with bitcoinpay.com - some payments may be outdated'
					];
					break;
				}
			}
			$newStatus = $data['status'];
			//when status is different (or new), save it and inform user
			if ($newStatus != $payment['status']) {
				Db::queryModify('UPDATE `payments` SET `status` = ? WHERE `id_payment` = ?', [$newStatus, $paymentId]);
				$messages[] = $bitcoinPay->getStatusMessage($newStatus);
				//and when receive money, make invoice in fakturoid payed
				if ($newStatus == ('confirmed')) {
					$fakturoid = new FakturoidWrapper();
					$fakturoid->setInvoicePayed($fakturoidId);
					Db::queryModify('UPDATE `payments`
						SET `payed_price_BTC` = ?
						WHERE `id_payment` = ?', [$data['settled_amount'], $paymentId]);
				}
			}
		}
		
		return $messages;
	}
	
	public function makeNewPayments($user, $tariff, $lang) {
		$new = false;
		$active = $user['active'];
		//generate new payments
		if ($active) {
			$userId = $user['id_user'];
			list($year, $month, $day) = explode('-', $user['invoicing_start_date']);
			$dbStartDate = mktime(0, 0, 0, $month, $day, $year);
			$currentDate = time();
			$startOfLastGeneratedMonth = Db::querySingleOne('
                SELECT `payment_first_date` FROM `payments`
                WHERE `id_payer` = ?
                ORDER BY `payment_first_date` DESC', [$userId]);
			
			if (empty($startOfLastGeneratedMonth)) {
				//add beginning for new user
				$startDate = $dbStartDate;
			}
			else {
				list($year, $month, $day) = explode('-', $startOfLastGeneratedMonth);
				$startOfLastGeneratedMonth = mktime(0, 0, 0, $month, $day, $year);
				//or deside when if use last day of previous payment or newly begin set
				if ($startOfLastGeneratedMonth >= $dbStartDate)
					$startDate = strtotime('+1 month', $startOfLastGeneratedMonth);
				else $startDate = $dbStartDate;
			}
			
			//and add following invoices till today
			while ($startDate <= $currentDate) {
				$this->createPayment($user, $tariff, $startDate, $lang);
				$startDate = strtotime('+1 month', $startDate);
				$new = true;
			}
			
		}
		if ($new == true)
			return true;
		else
			return false;
	}
	
	private function createPayment($user, $tariff, $beginningDate, $lang) {
		$userId = $user['id_user'];
		$tariffId = $tariff['id_tariff'];
		$tariffName = $this->getTariffName($tariffId, 'cs'); //invoice is in czech only
		$priceCZK = $tariff['priceCZK'];
		$fakturoid = new FakturoidWrapper();
		
		$fakturoidInvoice = $fakturoid->createInvoice($user, $tariff['priceCZK'], $tariffName, $beginningDate, $lang);
		if (!$fakturoidInvoice)
			return [
				's' => 'error',
				'cs' => 'Nepovedlo se spojení s fakturoid.cz. Zkuste to prosím za pár minut',
				'en' => 'We are unable to connect to fakturoid.cz. Try again in a few minutes'
			];
		$fakturoidInvoiceId = $fakturoidInvoice->id;
		$fakturoidInvoiceNumber = $fakturoidInvoice->number;
		$now = date('Y-m-d H-i-s');
		Db::queryModify('
			INSERT INTO `payments` (
				`id_payer`, 
				`payment_first_date`, 
				`status`, 
				`time_generated`, 
				`tariff_id`,
				`price_CZK`, 
				`invoice_fakturoid_id`, 
				`invoice_fakturoid_number`
		  	) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
			$userId,
			date('Y-m-d', $beginningDate),
			'unpaid',
			$now,
			$tariffId,
			$priceCZK,
			$fakturoidInvoiceId,
			$fakturoidInvoiceNumber
		]);
		
		//add blank extras
		$extras = new Extras;
		$blankExtras = $extras->getBlankExtras($user['id_user']);
		if (!empty($blankExtras)) {
			foreach ($blankExtras as $extra) {
				$extraId = $extra['id_extra'];
				$price = $extra['priceCZK'];
				$description = $extra['description'];
				$vat = $extra['vat'];
				$fakturoidExtraId = $fakturoid->addExtra($fakturoidInvoiceId, $price, $description, $vat);
				$paymentId = $this->getPaymentIdFromFakturoidInvoiceId($fakturoidInvoiceId);
				$extras->assignBlankExtra($paymentId, $price, $description, $fakturoidExtraId, $extraId);
			}
		}
		
		//get id of new payment
		$newInvoiceId = $this->getPaymentIdFromFakturoidInvoiceId($fakturoidInvoiceId);
		
		//send email to user
		$subject = NAME.' Paralelní Polis - nová faktura/new invoice';
		$link = ROOT;
		$linkCzech = ROOT.'/cs/PayInvoice/pay/'.$newInvoiceId;
		$linkEnglish = ROOT.'/en/PayInvoice/pay/'.$newInvoiceId;
		
		$message = '<div style="float: left;width: 45%;">Ahoj,<br/>
<br/>
vystavili jsme ti fakturu za členství / pronájem v Paper Hub v Paralelní Polis.<br/>
Platbu uhradíš jednoduše na tomto odkazu: <a href="'.$linkCzech.'">'.$linkCzech.'</a> (je potřeba být přihlášená/ý).<br/>
<br/>
Pokud odkaz nebude fungovat, seznam svých faktur najdeš po přihlášení zde: <a href="'.$link.'">'.$link.'</a><br/>
<br/>
Díky za rychlou platbu!<br/>
Paper Hub</div>
<div style="margin-left: 55%;">Hello,<br/>
<br/>
we\'ve invoiced your membership / rent in Paper Hub in Paralelní Polis.<br/>
You can easily pay by scanning this QR code in your BTC wallet: <a href="'.$linkEnglish.'">'.$linkEnglish.'</a> (you need to be logged in the System)<br/>
<br/>
If the link is not working, log in the System and find the list of your invoices here: <a href="'.$link.'">'.$link.'</a><br/>
<br/>
Thank you for your fast payment!<br/>
Best regards,<br/>
Paper Hub
</div>';
		$this->sendEmail(EMAIL, $user['email'], $subject, $message);
		//and send copy of email to hub manager
		//TODO refractor
		$this->sendEmail(EMAIL, EMAIL_HUB_MANAGER, NAME.' - Poslána výzva o nové faktuře na email '.$user['email'], $message);
		//controll thirth mail
		$this->sendEmail(EMAIL, EMAIL, NAME.' - Poslána výzva o nové faktuře na email '.$user['email'], $message);
		
		return ['s' => 'success'];
	}
	
	private function getExchangeRate() {
		$ch = curl_init();
		
		curl_setopt($ch, CURLOPT_URL, "https://bitcoinpay.com/api/v1/rates/btc");
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, false);
		
		$response = curl_exec($ch);
		curl_close($ch);
		
		$result = json_decode($response, true);
		foreach ($result as $r) {
			if (array_key_exists('CZK', $r))
				return $r['CZK'];
		}
		
		return false;
	}
	
	private function translatePaymentStatus($status, $lang) {
		$a = [
			'pending' => [
				'cs' => 'čekající',
				'en' => 'pending'
			],
			'confirmed' => [
				'cs' => 'potvrzená',
				'en' => 'confirmed'
			],
			'received' => [
				'cs' => 'přijato',
				'en' => 'received'
			],
			'insufficient_amount' => [
				'cs' => 'nedostatečná částka',
				'en' => 'insufficient amount'
			],
			'timeout' => [
				'cs' => 'platnost vypršela',
				'en' => 'timed out'
			],
			'paid_after_timeout' => [
				'cs' => 'zaplaceno pozdě',
				'en' => 'payed after payout'
			],
			'invalid' => [
				'cs' => 'invalid',
				'en' => 'invalid'
			],
			'unpaid' => [
				'cs' => 'nezaplaceno',
				'en' => 'unpaid'
			],
			'refund' => [
				'cs' => 'vráceno',
				'en' => 'refund'
			],
		];
		
		return $a[$status][$lang];
	}
	
	public function getExpiredPayments($toleranceDays) {
		$dbResults = Db::queryAll('SELECT `price_CZK`, `email`, `id_user` FROM `payments`
			JOIN `users` ON `users`.`id_user` = `payments`.`id_payer`
			WHERE `status` != (? || ?)', ['confirmed', 'received']);
		$result = [];
		foreach ($dbResults as $r) {
			$result [] = [
				'id_user' => $r['id_user'],
				'email' => $r['email'],
				'price_CZK' => $r['price_CZK']
			];
		}
		
		return $result;
	}
	
	private function getPaymentIdFromFakturoidInvoiceId($fakturoidInvoiceId) {
		return Db::querySingleOne('SELECT `id_payment` FROM `payments` WHERE `invoice_fakturoid_id` = ?', [$fakturoidInvoiceId]);
	}
}
