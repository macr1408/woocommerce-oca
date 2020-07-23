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
    if (!$order) return false;
    if ($order->get_shipping_address_1()) {
        $shipping_line_1 = $order->get_shipping_address_1();
        $shipping_line_2 = $order->get_shipping_address_2();
    } else {
        $shipping_line_1 = $order->get_billing_address_1();
        $shipping_line_2 = $order->get_billing_address_2();
    }
    $street_name = $street_number = $floor = $apartment = "";
    if (!empty($shipping_line_2)) {
        //there is something in the second line. Let's find out what
        $fl_apt_array = woo_oca_get_floor_and_apt($shipping_line_2);
        $floor = $fl_apt_array[0];
        $apartment = $fl_apt_array[1];
    }

    //Now let's work on the first line
    preg_match('/(^\d*[\D]*)(\d+)(.*)/i', $shipping_line_1, $res);
    $line1 = $res;

    if ((isset($line1[1]) && !empty($line1[1]) && $line1[1] !== " ") && !empty($line1)) {
        //everything's fine. Go ahead
        if (empty($line1[3]) || $line1[3] === " ") {
            //the user just wrote the street name and number, as he should
            $street_name = trim($line1[1]);
            $street_number = trim($line1[2]);
            unset($line1[3]);
        } else {
            //there is something extra in the first line. We'll save it in case it's important
            $street_name = trim($line1[1]);
            $street_number = trim($line1[2]);
            $shipping_line_2 = trim($line1[3]);

            if (empty($floor) && empty($apartment)) {
                //if we don't have either the floor or the apartment, they should be in our new $shipping_line_2
                $fl_apt_array = woo_oca_get_floor_and_apt($shipping_line_2);
                $floor = $fl_apt_array[0];
                $apartment = $fl_apt_array[1];
            } elseif (empty($apartment)) {
                //we've already have the floor. We just need the apartment
                $apartment = trim($line1[3]);
            } else {
                //we've got the apartment, so let's just save the floor
                $floor = trim($line1[3]);
            }
        }
    } else {
        //the user didn't write the street number. Maybe it's in the second line
        //given the fact that there is no street number in the fist line, we'll asume it's just the street name
        $street_name = $shipping_line_1;

        if (!empty($floor) && !empty($apartment)) {
            //we are in a pickle. It's a risky move, but we'll move everything one step up
            $street_number = $floor;
            $floor = $apartment;
            $apartment = "";
        } elseif (!empty($floor) && empty($apartment)) {
            //it seems the user wrote only the street number in the second line. Let's move it up
            $street_number = $floor;
            $floor = "";
        } elseif (empty($floor) && !empty($apartment)) {
            //I don't think there's a chance of this even happening, but let's write it to be safe
            $street_number = $apartment;
            $apartment = "";
        }
    }
    return array('street' => $street_name, 'number' => $street_number, 'floor' => $floor, 'apartment' => $apartment);
}

function woo_oca_get_floor_and_apt($fl_apt)
{
    //firts we'll asume the user did things right. Something like "piso 24, depto. 5h"
    preg_match('/(piso|p|p.) ?(\w+),? ?(departamento|depto|dept|dpto|dpt|dpt.º|depto.|dept.|dpto.|dpt.|apartamento|apto|apt|apto.|apt.) ?(\w+)/i', $fl_apt, $res);
    $line2 = $res;

    if (!empty($line2)) {
        //everything was written great. Now lets grab what matters
        $floor = trim($line2[2]);
        $apartment = trim($line2[4]);
    } else {
        //maybe the user wrote something like "depto. 5, piso 24". Let's try that
        preg_match('/(departamento|depto|dept|dpto|dpt|dpt.º|depto.|dept.|dpto.|dpt.|apartamento|apto|apt|apto.|apt.) ?(\w+),? ?(piso|p|p.) ?(\w+)/i', $fl_apt, $res);
        $line2 = $res;
    }

    if (!empty($line2) && empty($apartment) && empty($floor)) {
        //apparently, that was the case. Guess some people just like to make things difficult
        $floor = trim($line2[4]);
        $apartment = trim($line2[2]);
    } else {
        //something is wrong. Let's be more specific. First we'll try with only the floor
        preg_match('/^(piso|p|p.) ?(\w+)$/i', $fl_apt, $res);
        $line2 = $res;
    }

    if (!empty($line2) && empty($floor)) {
        //now we've got it! The user just wrote the floor number. Now lets grab what matters
        $floor = trim($line2[2]);
    } else {
        //still no. Now we'll try with the apartment
        preg_match('/^(departamento|depto|dept|dpto|dpt|dpt.º|depto.|dept.|dpto.|dpt.|apartamento|apto|apt|apto.|apt.) ?(\w+)$/i', $fl_apt, $res);
        $line2 = $res;
    }

    if (!empty($line2) && empty($apartment) && empty($floor)) {
        //success! The user just wrote the apartment information. No clue why, but who am I to judge
        $apartment = trim($line2[2]);
    } else {
        //ok, weird. Now we'll try a more generic approach just in case the user missplelled something
        preg_match('/(\d+),? [a-zA-Z.,!*]* ?([a-zA-Z0-9 ]+)/i', $fl_apt, $res);
        $line2 = $res;
    }

    if (!empty($line2) && empty($floor) && empty($apartment)) {
        //finally! The user just missplelled something. It happens to the best of us
        $floor = trim($line2[1]);
        $apartment = trim($line2[2]);
    } else {
        //last try! This one is in case the user wrote the floor and apartment together ("12C")
        preg_match('/(\d+)(\D*)/i', $fl_apt, $res);
        $line2 = $res;
    }

    if (!empty($line2) && empty($floor) && empty($apartment)) {
        //ok, we've got it. I was starting to panic
        $floor = trim($line2[1]);
        $apartment = trim($line2[2]);
    } elseif (empty($floor) && empty($apartment)) {
        //I give up. I can't make sense of it. We'll save it in case it's something useful 
        $floor = $fl_apt;
    }

    return array($floor, $apartment);
}
