<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}


// =========================================================================
/**
 * Function generar_envio_oca
 *
 */
add_action('woocommerce_order_status_completed', 'woo_oca_generar_envio_oca');
function woo_oca_generar_envio_oca($order_id)
{
    $logger = wc_get_logger();
    $order = wc_get_order($order_id);
    $envio_seleccionado = reset($order->get_items('shipping'))->get_method_id();
    $envio = explode(" ", $envio_seleccionado);
    if ($envio[0] === 'oca') {
        $datos = unserialize(get_option('oca_fields_settings'));
        $operativa = unserialize($order->get_meta('oca_shipping_info'));
        $xml = woo_oca_crear_datos_oca($datos, $order, $operativa);
        require_once plugin_dir_path(__FILE__) . 'oca/autoload.php';
        $oca = new Oca($datos['cuit'], $operativa['code']);
        $logger->debug('=== Ingresando el envío en el sistema de OCA ===', unserialize(OCA_LOGGER_CONTEXT));
        $logger->debug($xml, unserialize(OCA_LOGGER_CONTEXT));
        $data = $oca->ingresoORMultiplesRetiros($datos['username'], $datos['password'], $xml, true);
        if (!isset($data[0]['error'])) {
            $numeroenvio = $data[0]['NumeroEnvio'];
            $ordenretiro = $data[0]['OrdenRetiro'];
            $order->update_meta_data('numeroenvio_oca', $numeroenvio);
            $order->update_meta_data('ordenretiro_oca', $ordenretiro);
            $order->save();
            $logger->debug('Envío Realizado con exito. Nro. Envio: ' . $numeroenvio . ' | Orden retiro: ' . $ordenretiro, unserialize(OCA_LOGGER_CONTEXT));
        } else {
            $logger->error('Error al realizar envío: ' . $data[0]['error'], unserialize(OCA_LOGGER_CONTEXT));
        }
    }
}

// =========================================================================
/**
 * Function crear_datos_oca
 *
 */
function woo_oca_crear_datos_oca($datos = array(), $order = '', $operativa = '')
{

    $countries_obj = new WC_Countries();
    $country_states_array = $countries_obj->get_states();
    $addressTo = woo_oca_get_address($order);
    if (!empty($order->get_shipping_first_name())) {
        $provincia = $country_states_array['AR'][$order->get_shipping_state()];
        $datos['nombre_cliente'] = $order->get_shipping_first_name();
        $datos['apellido_cliente'] = ' ' . $order->get_shipping_last_name();
        $datos['direccion_cliente'] = $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2();
        $datos['ciudad_cliente'] = $order->get_shipping_city();
        $datos['cp_cliente'] = $order->get_shipping_postcode();
    } else {
        $provincia = $country_states_array['AR'][$order->get_billing_state()];
        $datos['nombre_cliente'] = $order->get_billing_first_name();
        $datos['apellido_cliente'] = ' ' . $order->get_billing_last_name();
        $datos['direccion_cliente'] = $order->get_billing_address_1() . ' ' . $order->get_billing_address_2();
        $datos['ciudad_cliente'] = $order->get_billing_city();
        $datos['cp_cliente'] = $order->get_billing_postcode();
    }
    $datos['observaciones_cliente'] = $order->get_customer_note();
    $datos['provincia_cliente'] = $provincia;
    $datos['telefono_cliente'] = $order->get_billing_phone();
    $datos['celular_cliente'] = $order->get_billing_phone();
    $datos['email_cliente'] = $order->get_billing_email();
    $datos['sucursal_oca_destino'] = $order->get_meta('sucursal_oca_destino');
    if (empty($datos['sucursal_oca_destino'])) {
        $datos['sucursal_oca_destino'] = 0;
    }
    $datos['valor_declarado'] = $order->get_meta('precio_real_envio');
    $datos['valor_declarado'] = str_replace(',', "", $datos['valor_declarado']);

    if (html_entity_decode($datos['provincia_cliente']) == 'Tucumán') {
        $datos['provincia_cliente'] = 'Tucumán';
    }

    // Se filtran los caracteres
    $datos = array_map(function ($value) {
        $value = str_replace('"', "", $value);
        $value = str_replace("'", "", $value);
        $value = str_replace(";", "", $value);
        $value = str_replace("&", "", $value);
        $value = str_replace("<", "", $value);
        $value = str_replace(">", "", $value);
        $value = str_replace("º", "", $value);
        $value = str_replace("ª", "", $value);
        return $value;
    }, $datos);

    if ($operativa['type'] == 'pap' || $operativa['type'] == 'pas') {
        $centro_imposicion = '';
    } else {
        $centro_imposicion = 'idcentroimposicionorigen="' . $datos['id-origin'] . '" ';
    }

    $xml = '<?xml version="1.0" encoding="iso-8859-1" standalone="yes"?>
	<ROWS>   
		<cabecera ver="2.0" nrocuenta="' . $datos['account-number'] . '" />   
		<origenes>     
			<origen calle="' . $datos['street'] . '" nro="' . $datos['street-number'] . '" piso="' . $datos['floor'] . '" depto="' . $datos['apartment'] . '" cp="' . $datos['postal-code'] . '" localidad="' . $datos['locality'] . '" provincia="' . $datos['province'] . '" contacto="' . $datos['contact-name'] . '" email="' . $datos['email'] . '" solicitante="' . $datos['store-name'] . '" observaciones="" centrocosto="1" idfranjahoraria="' . $datos['timezone'] . '" ' . $centro_imposicion . 'fecha="' . current_time('Ymd') . '">       
				<envios>         
					<envio idoperativa="' . $operativa['code'] . '" nroremito="' . $order->get_order_number() . '">';
    $xml .= '<destinatario apellido="' . $datos['apellido_cliente'] . '" nombre="' . $datos['nombre_cliente'] . '" calle="' . $addressTo['street'] . '" nro="' . $addressTo['number'] . '" piso="' . $addressTo['floor'] . '" depto="' . $addressTo['apartment'] . '" localidad="' . $datos['ciudad_cliente'] . '" provincia="' . $datos['provincia_cliente'] . '" cp="' . $datos['cp_cliente'] . '" telefono="' . $datos['telefono_cliente'] . '" email="' . $datos['email_cliente'] . '" idci="' . $datos['sucursal_oca_destino'] . '" celular="' . $datos['celular_cliente'] . '" observaciones="' . $datos['observaciones_cliente'] . '" />';
    $xml .= '<paquetes>';
    $items = $order->get_items();
    foreach ($items as $item) {
        $product_name = $item['name'];
        $product_id = $item['product_id'];
        $product_variation_id = $item['variation_id'];
        $product = wc_get_product($product_id);
        $product_variado = wc_get_product($product_variation_id);
        //Se obtienen los datos del producto
        if ($product->get_weight() !== '') {
            $peso = $product->get_weight();
            $xml .= '<paquete alto="' . wc_get_dimension($product->get_height(), 'm') . '" ancho="' . wc_get_dimension($product->get_width(), 'm') . '" largo="' . wc_get_dimension($product->get_length(), 'm') . '" peso="' . wc_get_weight($peso, 'kg') . '" valor="' . $item->get_total() . '" cant="' . $item->get_quantity() . '" />';
        } else {
            $peso = $product_variado->get_weight();
            $xml .= '<paquete alto="' . wc_get_dimension($product_variado->get_height(), 'm') . '" ancho="' . wc_get_dimension($product_variado->get_width(), 'm') . '" largo="' . wc_get_dimension($product_variado->get_length(), 'm') . '" peso="' . wc_get_weight($peso, 'kg') . '" valor="' . $item->get_total() . '" cant="' . $item->get_quantity() . '" />';
        }
    }
    $xml .= '</paquetes>         
					</envio>       
				</envios>     
			</origen>   
		</origenes> 
	</ROWS> ';
    return $xml;
}

function woo_oca_get_address($order)
{
    if (!$order) {
        return false;
    }
    if ($order->get_shipping_address_1()) {
        $shipping_line_1 = trim($order->get_shipping_address_1());
        $shipping_line_2 = trim($order->get_shipping_address_2());
    } else {
        $shipping_line_1 = trim($order->get_billing_address_1());
        $shipping_line_2 = trim($order->get_billing_address_2());
    }
	
    $shipping_line_1 = woo_oca_remove_accents($shipping_line_1);
    $shipping_line_2 = woo_oca_remove_accents($shipping_line_2);

    if (empty($shipping_line_2)) {
        // Av. Mexico 430, Piso 4 B
        preg_match('/([^,]+),(.+)/i', $shipping_line_1, $res);
        if (!empty($res)) {
            $shipping_line_1 = trim($res[1]);
            $shipping_line_2 = trim($res[2]);
        }
    }

    $street_name = $street_number = $floor = $apartment = "";
    if (!empty($shipping_line_2)) {
        //there is something in the second line. Let's find out what
        $fl_apt_array = woo_oca_get_floor_and_apt($shipping_line_2);
        $street_number = $fl_apt_array['number'];
        $floor = $fl_apt_array['floor'];
        $apartment = $fl_apt_array['apartment'];
    }

    // Street number detected in second line, check only for words in first line, it should be the street name
    if ($street_number) {
        preg_match('/^[a-zA-Z ]+$/i', $shipping_line_1, $res);
        if (!empty($res)) {
            $street_name = trim($res[0]);
            return array('street' => $street_name, 'number' => $street_number, 'floor' => $floor, 'apartment' => $apartment);
        }
    }

    // Av. Mexico 430
    preg_match('/^([a-zA-Z.#º ]+)[ ]+(\d+)$/i', $shipping_line_1, $res);
    if (!empty($res)) {
        $street_name = trim($res[1]);
        $street_number = trim($res[2]);
        return array('street' => $street_name, 'number' => $street_number, 'floor' => $floor, 'apartment' => $apartment);
    }

    // calle 27 nro. 1458
    preg_match('/^(calle|avenida|av)[\.#º]*[ ]+([0-9]+)[ ]+(numero|altura|nro)[\.#º]*[ ]+([0-9]+)$/i', $shipping_line_1, $res);
    if (!empty($res)) {
        $street_name = trim($res[1] . ' ' . $res[2]);
        $street_number = trim($res[4]);
        return array('street' => $street_name, 'number' => $street_number, 'floor' => $floor, 'apartment' => $apartment);
    }

    // 27 nro 1458
    preg_match('/^(\d+)[ ]+(numero|altura|nro)[\.#º]*[ ]+([0-9]+)$/i', $shipping_line_1, $res);
    if (!empty($res)) {
        $street_name = trim($res[1]);
        $street_number = trim($res[3]);
        return array('street' => $street_name, 'number' => $street_number, 'floor' => $floor, 'apartment' => $apartment);
    }
	
    // Fallback
    $fallback = $shipping_line_1;
    if(empty($floor) && empty($apartment)){
        $fallback .= ' ' . $shipping_line_2;
    }
    return array('street' => $fallback, 'number' => $street_number, 'floor' => $floor, 'apartment' => $apartment);
}

function woo_oca_get_floor_and_apt($fl_apt)
{
    $street_name = $street_number = $floor = $apartment = "";

    // Piso 8, dpto. A
    preg_match('/^(piso|pso|pis|p)[\.º#]*[ ]*(\w+)[, ]*(departamento|depto|dept|dep|dpto|dpt|dto|apartamento|apto|apt)[\.º#]*[ ]*(\w+)[, ]*/i', $fl_apt, $res);
    if (!empty($res)) {
        $floor = trim($res[2]);
        $apartment = trim($res[4]);
        return array('street' => $street_name, 'number' => $street_number, 'floor' => $floor, 'apartment' => $apartment);
    }

    // Piso PB
    preg_match('/^(piso|pso|pis|p)[\.º#]*[ ]*(\w+)$/i', $fl_apt, $res);
    if (!empty($res)) {
        $floor = trim($res[2]);
        return array('street' => $street_name, 'number' => $street_number, 'floor' => $floor, 'apartment' => $apartment);
    }

    // 1420 Piso 8, dpto. A
    preg_match('/^([\d]+)[ ]+(piso|pso|pis|p)[\.º#]*[ ]*(\w+)[, ]*(departamento|depto|dept|dep|dpto|dpt|dto|apartamento|apto|apt)[\.º#]*[ ]*(\w+)[, ]*/i', $fl_apt, $res);
    if (!empty($res)) {
        $street_number = trim($res[1]);
        $floor = trim($res[3]);
        $apartment = trim($res[5]);
        return array('street' => $street_name, 'number' => $street_number, 'floor' => $floor, 'apartment' => $apartment);
    }

    // 1420 Piso 8
    preg_match('/^([\d]+)[ ]+(piso|pso|pis|p)[\.º#]*[ ]*(\w+)[, ]*/i', $fl_apt, $res);
    if (!empty($res)) {
        $street_number = trim($res[1]);
        $floor = trim($res[3]);
        return array('street' => $street_name, 'number' => $street_number, 'floor' => $floor, 'apartment' => $apartment);
    }

    // Depto. 5, piso 24
    preg_match('/^(departamento|depto|dept|dep|dpto|dto|apartamento|apto|apt)[\.º#]*[ ]*(\w+)[, ]*(piso|pso|pis|p)[\.º#]*[ ]*(\w+)[, ]*/i', $fl_apt, $res);
    if (!empty($res)) {
        $floor = trim($res[4]);
        $apartment = trim($res[2]);
        return array('street' => $street_name, 'number' => $street_number, 'floor' => $floor, 'apartment' => $apartment);
    }

    // depto 4A
    preg_match('/^(departamento|depto|dept|dep|dpto|dpt|dto|apartamento|apto|apt)[\.º#]*[ ]*(\w+)$/i', $fl_apt, $res);
    if (!empty($res)) {
        $apartment = trim($res[2]);
        return array('street' => $street_name, 'number' => $street_number, 'floor' => $floor, 'apartment' => $apartment);
    }

    // 2 B
    preg_match('/^(\d+)[ ]*(\D+)$/i', $fl_apt, $res);
    if (!empty($res)) {
        $floor = trim($res[1]);
        $apartment = trim($res[2]);
        return array('street' => $street_name, 'number' => $street_number, 'floor' => $floor, 'apartment' => $apartment);
    }

    //I give up. I can't make sense of it. We'll save it in case it's something useful 
    return array('street' => $street_name, 'number' => $street_number, 'floor' => $fl_apt, 'apartment' => $apartment);
}

function woo_oca_remove_accents($str, $charset = 'utf-8') {
    $str = htmlentities($str, ENT_NOQUOTES, $charset);
    $str = preg_replace('#&([A-za-z])(?:acute|cedil|caron|circ|grave|orn|ring|slash|th|tilde|uml);#', '\1', $str);
    $str = preg_replace('#&([A-za-z]{2})(?:lig);#', '\1', $str);
    $str = preg_replace('#&[^;]+;#', '', $str);
    return $str;
}
