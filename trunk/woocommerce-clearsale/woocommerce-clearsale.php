<?php
/*
Plugin Name: WooCommerce ClearSale
Plugin URI: http://www.wooplugins.com.br
Description: Faz a integração do WooCommerce com o sistema anti-fraude da ClearSale
Version: 0.1
Author: F2M Tecnologia <contato@f2mtecnologia.com.br>
Author URI: http://www.f2mtecnologia.com.br
License: Commercial
Requires at least: 3.4
Tested up to: 3.5.2
*/


class F2M_ClearSale {
    public function __construct(){
		//set the clearsale test and production urls
		$this->test_register_url = "http://homologacao.clearsale.com.br/integracaov2/freeclearsale/frame.aspx";
		$this->test_update_url = "http://homologacao.clearsale.com.br/integracaov2/FreeClearSale/AlterarStatus.aspx";
		$this->prod_register_url = "http://www.clearsale.com.br/integracaov2/freeclearsale/";
		$this->prod_update_url = "http://clearsale.com.br/integracaov2/FreeClearSale/AlterarStatus.aspx";
		
        if(is_admin()){
			//create the clearsale menu on admin panel
			add_action('admin_menu', array($this, 'add_plugin_page'));
			//register all clearsale main settings
			add_action('admin_init', array($this, 'page_init'));
			//create the action buttons in the order page
			add_action('woocommerce_order_actions_end', array($this, 'f2m_clearsale_order_options')); 
			//execute the action selected in the order page
			add_action('save_post', array($this, 'f2m_clearsale_process_options')); 
			
			//all hooks here will be used to create or update the status of the order with ClearSale
			add_action('woocommerce_new_order', array($this, 'f2m_clearsale_process_new_order')); 
			add_action('woocommerce_order_status_changed', array($this, 'f2m_clearsale_process_order_status')); 
			add_action('woocommerce_payment_complete', array($this, 'f2m_clearsale_process_order_payment')); 
		}
    }
	
    public function add_plugin_page(){
        // This page will be under "Settings"
		add_options_page('ClearSale', 'ClearSale', 'manage_options', 'f2m-clearsale-settings', array($this, 'create_admin_page'));
    }

    public function create_admin_page(){
        ?>
			<div class="wrap">
				<?php screen_icon(); ?>
				<h2>Configurações do ClearSale</h2>			
				<form method="post" action="options.php">
					<?php
						// This prints out all hidden setting fields
						settings_fields('f2m_clearsale_settings');	
						do_settings_sections('f2m_clearsale_section_main');
						submit_button(); 
					?>
				</form>
			</div>
		<?php
    }
	
    public function page_init(){		
		register_setting('f2m_clearsale_settings', 'f2m_clearsale_settings');
        add_settings_section('setting_section_id','Setting', array($this, 'print_section_info'), 'f2m_clearsale_section_main');	
		add_settings_field('f2m_clearsale_field_token', 'Código de Integração', array($this, 'create_token_field'), 'f2m_clearsale_section_main', 'setting_section_id');		
		add_settings_field('f2m_clearsale_field_is_prod', 'Executar em Produção?', array($this, 'create_is_prod_field'), 'f2m_clearsale_section_main', 'setting_section_id');		
    }
	
    public function print_section_info(){
		print 'Digite as informações abaixo para configurar o ClearSale:';
    }
	
    public function create_token_field(){
		$options = get_option('f2m_clearsale_settings');
		echo "<input id='f2m_clearsale_field_token' name='f2m_clearsale_settings[f2m_clearsale_field_token]' size='40' type='text' value='{$options['f2m_clearsale_field_token']}' />";
    }	
	
    public function create_is_prod_field(){
		$options = get_option('f2m_clearsale_settings');
		echo "<input id='f2m_clearsale_field_is_prod' name='f2m_clearsale_settings[f2m_clearsale_field_is_prod]' size='40' type='checkbox' value='production' ".($options['f2m_clearsale_field_is_prod'] == 'production' ? 'checked' : '')." />";
    }
	
	
	private function generate_clearsale_update_args($order_id){
		global $woocommerce;
		
		//get the plugin settings
		$settings = get_option('f2m_clearsale_settings');
		
		//create woocommerce order object
		$order = &new WC_Order( $order_id );
		
		$status = get_post_meta($order_id, 'f2m_clearsale_status', true);
		
		if($status == ''){
			return false;
		} else {
			$clearsale_args = array(
				"CodigoIntegracao"					=> $settings['f2m_clearsale_field_token'],

				//DADOS DO PEDIDO
				"PedidoID"							=> $order->id,
				"Status" 							=> $status,
			);
		}
				
		return $clearsale_args;
	}	
	
	private function generate_clearsale_form_args($order_id, $add_products=true){
		global $woocommerce;
		
		//get the plugin settings
		$settings = get_option('f2m_clearsale_settings');
		
		//create woocommerce order object
		$order = &new WC_Order( $order_id );
		
		//clear telephone data
		$phone = str_replace( array( '(', '-', ' ', ')', '.' ), '', $order->billing_phone );
		$phone_ddd = substr($phone, 0, 2);
		$phone_number = substr($phone, 2);


		//get the current status and payment type to make the correct selection 
		$forma_pagto = get_post_meta($order_id, 'f2m_clearsale_forma_pagto', true);
		$parcelas = get_post_meta($order_id, 'f2m_clearsale_parcelas', true);

		
		if(!$forma_pagto && !$parcelas){
			//Check the payment method. It will try to define the payment method if F2M_Cielo_Direto or F2M_Moip_Transparente
			//payment method was used, otherwise it will put a default value
			$meio_pagto = get_post_meta($order_id, '_payment_method', true);
			
			switch($meio_pagto){
				case 'moiptransparente':
					$pagto = get_post_meta($order->id, '_f2m_moiptransparente_tipo_pagto', true);
					$parcelas = get_post_meta($order->id, '_f2m_moiptransparente_parcelas', true);
					switch($pagto){
						case 'BoletoBancario':
							$forma_pagto = 2;
							break;
						case 'CartaoDeCredito':
							$forma_pagto = 1;
							break;
						case 'DebitoBancario':
							$forma_pagto = 3;
							break;
						case 'CartaoDeDebito':
							$forma_pagto = 3;
							break;
						case 'FinanciamentoBancario':
							$forma_pagto = 10;
							break;
						case 'CarteiraMoIP':
							$forma_pagto = 14;
							break;
						default:
							$forma_pagto = 14;
							$parcelas = 1;
							break;
					}
					break;
				case 'cielodireto':
					$pagto = get_post_meta($order->id, '_f2m_cielodireto_tipo_pagto', true);
					$parcelas = get_post_meta($order->id, '_f2m_cielodireto_parcelas', true);
					switch($pagto){
						case '1':
							$forma_pagto = 2;
							break;
						case '2':
							$forma_pagto = 2;
							break;
						case '3':
							$forma_pagto = 2;
							break;
						case 'A':
							$forma_pagto = 3;
							break;
						default:
							$forma_pagto = 14;
							$parcelas = 1;
							break;
					}
					break;
				default:
					$forma_pagto = 14;
					$parcelas = 1;
			}
			
			delete_post_meta($order_id, 'f2m_clearsale_forma_pagto');
			update_post_meta($order_id, 'f2m_clearsale_forma_pagto', $forma_pagto, true);
			
			delete_post_meta($order_id, 'f2m_clearsale_parcelas');
			update_post_meta($order_id, 'f2m_clearsale_parcelas', $parcelas, true);
			
			delete_post_meta($order_id, 'f2m_clearsale_meio_pagto');
			update_post_meta($order_id, 'f2m_clearsale_meio_pagto', $meio_pagto, true);
		}
		
		
		$clearsale_args = array(
			"CodigoIntegracao"					=> $settings['f2m_clearsale_field_token'],

			//DADOS DO PEDIDO
			"PedidoID"							=> $order->id,
			"Data" 								=> $order->order_date,//"28/06/2013 14:10:00",//
			"IP"								=> get_post_meta($order->id, "_customer_ip_address", true),
			"Total"								=> $order->get_total(),
			"TipoPagamento" 					=> $forma_pagto,
			"Parcelas" 							=> $parcelas,
			
			//DADOS DO CLIENTE
			"Cobranca_Nome" 					=> $order->billing_first_name . " " . $order->billing_last_name,
			"Cobranca_Email" 					=> $order->billing_email,
			"Cobranca_Documento" 				=> get_post_meta($order->id, '_billing_cpf', true),
			"Cobranca_Logradouro" 				=> $order->billing_address_1,
			"Cobranca_Logradouro_Numero" 		=> get_post_meta($order->id, '_billing_number', true),
			"Cobranca_Logradouro_Complemento" 	=> $order->billing_address_2,
			"Cobranca_Bairro" 					=> get_post_meta($order->id, '_billing_district', true),
			"Cobranca_Cidade" 					=> $order->billing_city,
			"Cobranca_Estado" 					=> $order->billing_state,
			"Cobranca_CEP" 						=> $order->billing_postcode,
			"Cobranca_Pais" 					=> ($order->billing_country == 'BR' ? "BRA" : $order->billing_country),
			"Cobranca_DDD_Telefone" 			=> $phone_ddd,
			"Cobranca_Telefone" 				=> $phone_number,
			
			//DADOS DE ENTREGA
			"Entrega_Nome" 						=> $order->billing_first_name . " " . $order->billing_last_name,
			"Entrega_Email" 					=> $order->billing_email,
			"Entrega_Documento" 				=> get_post_meta($order->id, '_billing_cpf', true),
			"Entrega_Logradouro" 				=> $order->billing_address_1,
			"Entrega_Logradouro_Numero" 		=> get_post_meta($order->id, '_billing_number', true),
			"Entrega_Logradouro_Complemento" 	=> $order->billing_address_2,
			"Entrega_Bairro" 					=> get_post_meta($order->id, '_billing_district', true),
			"Entrega_Cidade" 					=> $order->billing_city,
			"Entrega_Estado" 					=> $order->billing_state,
			"Entrega_CEP" 						=> $order->billing_postcode,
			"Entrega_Pais" 						=> ($order->billing_country == 'BR' ? "BRA" : $order->billing_country),
			"Entrega_DDD_Telefone" 				=> $phone_ddd,
			"Entrega_Telefone" 					=> $phone_number,
		);
		
		if($add_products){
			//DADOS DOS PRODUTOS DO PEDIDO
			$i = 0;
			if (sizeof( $order->get_items() ) > 0) {
				foreach ($order->get_items() as $item) {
					if ($item['qty']) {
						$i++;
						$product = $order->get_product_from_item($item);

						$clearsale_args['Item_ID_'.$i] = $product->get_sku();
						$clearsale_args['Item_Nome_'.$i] = $item['name'];
						$clearsale_args['Item_Qtd_'.$i] = $item['qty'];
						$clearsale_args['Item_Valor_'.$i] = $product->get_price_including_tax();
					}
				}
			}
		}
		
		return $clearsale_args;
	}
	
	
	public function f2m_clearsale_order_options($order_id){
		//get the plugin settings
		$settings = get_option('f2m_clearsale_settings');
		
		//get the clearsale arguments to create GET method
		$clearsale_args = $this->generate_clearsale_form_args($order_id);
		
		//get the clearsale URL for the request (production or test server)
		$url = ($settings['f2m_clearsale_field_is_prod']=='production'?$this->prod_register_url:$this->test_register_url);
		
		//put the GET params into URL
		$url = $url.'?'.trim(http_build_query($clearsale_args));
		
		//get the current status and payment type to make the correct selection 
		$status = get_post_meta($order_id, 'f2m_clearsale_status', true);
		$forma_pagto = get_post_meta($order_id, 'f2m_clearsale_forma_pagto', true);
		$parcelas = get_post_meta($order_id, 'f2m_clearsale_parcelas', true);
		
		$actions = '<h3>ClearSale Status</h3>';
		if($forma_pagto && $parcelas){
			$actions .= '<iframe src="'.$url.'" width="280"height="85" frameborder="0" scrolling="no"><P>Seu Browser não suporta iframes</P></iframe>';
			if(!$status){
				$actions .= 'Alterar Status: 
								<select name="f2m_clearsale_status" id="f2m_clearsale_status" '.($status!=''?'disabled':'').'>
									<option value="NDA" '.($status=='NDA'?'selected':'').'>selecione um status</option>
									<option value="CAN" '.($status=='CAN'?'selected':'').'>Cancelado pelo Cliente</option>
									<option value="SUS" '.($status=='SUS'?'selected':'').'>Suspeito</option>
									<option value="APM" '.($status=='APM'?'selected':'').'>Aprovado</option>
									<option value="FRD" '.($status=='FRD'?'selected':'').'>Fraude Confirmada</option>
									<option value="RPM" '.($status=='RPM'?'selected':'').'>Reprovado</option>
								</select><br>';		
			}
		} else {
			$actions .= 'Favor inserir as informações abaixo para consultar o clearsale e depois clicar em salvar:<br>';
			$actions .= 'Forma Pagto: 
							<select name="f2m_clearsale_forma_pagto" id="f2m_clearsale_forma_pagto">
								<option value="0"  '.($forma_pagto=='0'?'selected':'').'>selecione forma pagto</option>
								<option value="1"  '.($forma_pagto=='1'?'selected':'').'>Cartão de Crédito</option>
								<option value="2"  '.($forma_pagto=='2'?'selected':'').'>Boleto Bancário</option>
								<option value="3"  '.($forma_pagto=='3'?'selected':'').'>Débito Bancário</option>
								<option value="4"  '.($forma_pagto=='4'?'selected':'').'>Débito Bancário - Dinheiro</option>
								<option value="5"  '.($forma_pagto=='5'?'selected':'').'>Débito Bancário - Cheque</option>
								<option value="6"  '.($forma_pagto=='6'?'selected':'').'>Transferência Bancária</option>
								<option value="7"  '.($forma_pagto=='7'?'selected':'').'>Sedex à Cobrar</option>
								<option value="8"  '.($forma_pagto=='8'?'selected':'').'>Cheque</option>
								<option value="9"  '.($forma_pagto=='9'?'selected':'').'>Dinheiro</option>
								<option value="10" '.($forma_pagto=='10'?'selected':'').'>Financiamento</option>
								<option value="11" '.($forma_pagto=='11'?'selected':'').'>Fatura</option>
								<option value="12" '.($forma_pagto=='12'?'selected':'').'>Cupom</option>
								<option value="13" '.($forma_pagto=='13'?'selected':'').'>Multicheque</option>
								<option value="14" '.($forma_pagto=='14'?'selected':'').'>Outros</option>
							</select>';			
			$actions .= 'Parcelas: <input type="text" name="f2m_clearsale_parcelas" id="f2m_clearsale_parcelas" value="'.$parcelas.'" />';
		}
		
		//$clearsale_args = $this->generate_clearsale_form_args($order_id);
		//delete_post_meta($order_id, 'f2m_teste');
		//update_post_meta($order_id, 'f2m_teste', http_build_query($clearsale_args), true);
		update_post_meta($order_id, 'f2m_clearsale_url', $url, true);
		
		//show the iframe
		echo $actions;
	}
	
	public function f2m_clearsale_process_options($order_id){
		// don't run the echo if this is an auto save
		if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE )
			return;
		
		// don't run the echo if the function is called for saving revision.
		$post_object = get_post( $order_id );
		if ( $post_object->post_type == 'revision' )
			return;
		
		
		//print_r($_POST);
		
		remove_action('save_post', 'f2m_clearsale_process_options');

		global $woocommerce;

		//get the plugin settings
		$settings = get_option('f2m_clearsale_settings');

		//create woocommerce order object
		$order = &new WC_Order( $order_id );
		
		//status do pedido - manual
		if(isset($_POST['f2m_clearsale_status']) && $_POST['f2m_clearsale_status'] != "NDA"){
			delete_post_meta($order_id, 'f2m_clearsale_status');
			update_post_meta($order_id, 'f2m_clearsale_status', $_POST['f2m_clearsale_status'], true);
			$this->f2m_clearsale_process_order_status($order_id, null, null);
		}
		//forma de pagamento
		if(isset($_POST['f2m_clearsale_forma_pagto']) && $_POST['f2m_clearsale_forma_pagto']){
			delete_post_meta($order_id, 'f2m_clearsale_forma_pagto');
			update_post_meta($order_id, 'f2m_clearsale_forma_pagto', $_POST['f2m_clearsale_forma_pagto'], true);
		}
		//numero de parcelas
		if(isset($_POST['f2m_clearsale_parcelas']) && $_POST['f2m_clearsale_parcelas']){
			delete_post_meta($order_id, 'f2m_clearsale_parcelas');
			update_post_meta($order_id, 'f2m_clearsale_parcelas', $_POST['f2m_clearsale_parcelas'], true);
		}

		// We are here so lets check status and do actions
		//$order->add_order_note( __('Status Clearsale alterado para '.$_POST['f2m_clearsale_status'], 'woothemes') );

		add_action('save_post', array($this, 'f2m_clearsale_process_options')); 
	}
	
	public function f2m_clearsale_process_new_order($order_id){
		//TODO: process a new order, submitting the information to clearsale

		//get the plugin settings
		$settings = get_option('f2m_clearsale_settings');

		//get the clearsale URL for the request (production or test server)
		$url = ($settings['f2m_clearsale_field_is_prod']=='production'?$this->prod_register_url:$this->test_register_url);

		//get the clearsale arguments to create GET method
		$clearsale_args = $this->generate_clearsale_form_args($order_id);

		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($curl, CURLOPT_TIMEOUT, 40);	
		curl_setopt($curl, CURLOPT_USERAGENT, "Mozilla/4.0");
		curl_setopt($curl, CURLOPT_POST, true);
		curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($clearsale_args));
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		$ret = curl_exec($curl);
		$err = curl_error($curl);
		curl_close($curl);	

		//create woocommerce order object
		//$order = &new WC_Order( $order_id );
		
		// We are here so lets check status and do actions
		//$order->add_order_note( __($clearsale_args, 'woothemes') );
		//$order->add_order_note( __($ret, 'woothemes') );
		//update_post_meta($order_id, 'f2m_clearsale_url', $url, true);
	}
	
	public function f2m_clearsale_process_order_status($order_id, $old_status, $new_status){
		//TODO: process an order status change, updating clearsale data

		//get the plugin settings
		$settings = get_option('f2m_clearsale_settings');

		//get the clearsale URL for the request (production or test server)
		$url = ($settings['f2m_clearsale_field_is_prod']=='production'?$this->prod_update_url:$this->test_update_url);

		//get the clearsale arguments to create GET method
		$clearsale_args = $this->generate_clearsale_update_args($order_id);

		if($clearsale_args){
			$curl = curl_init();
			curl_setopt($curl, CURLOPT_URL, $url);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
			curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
			curl_setopt($curl, CURLOPT_TIMEOUT, 40);	
			curl_setopt($curl, CURLOPT_USERAGENT, "Mozilla/4.0");
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($clearsale_args));
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			$ret = curl_exec($curl);
			$err = curl_error($curl);
			curl_close($curl);	

			//create woocommerce order object
			$order = &new WC_Order( $order_id );
			
			
			update_post_meta($order->id, 'clearsale_update_status', $ret, true);
			// We are here so lets check status and do actions
			$order->add_order_note( $ret );
		}
	}
	
	public function f2m_clearsale_process_order_payment(){
		//TODO: process an order with a complete payment status, updating clearsale data
	}
	
}

$f2m_clearsale = new F2M_ClearSale();

?>