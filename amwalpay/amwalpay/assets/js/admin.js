/**
 *
 * Paymob payment plugin
 *
 * @author $URI: https://paymob.com
 * @author Paymob Development Team
 * @version $Id: admin.js
 * @package VirtueMart
 * @subpackage payment
 * Copyright (C) 2004 - 2020 Virtuemart Team. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 * VirtueMart is free software. This version may have been modified pursuant
 * to the GNU General Public License, and as distributed it includes or
 * is derivative of works licensed under the GNU General Public License or
 * other free or open source software licenses.
 * See /administrator/components/com_virtuemart/COPYRIGHT.php for copyright notices and details.
 *
 * http://virtuemart.net
 */

jQuery(document).ready(function () {
    jQuery('#paymob-login').click(function () {
        callAjax();
    });
    callAjax();
    function callAjax() {
        var paymob_api_key = jQuery('#params_api_key').val();
        var paymob_secret_key = jQuery('#params_secret_key').val();
        var paymob_public_key = jQuery('#params_public_key').val();
        var url = joomlaRoot + 'index.php?option=com_paymobinfo';

        if (paymob_api_key.length === 0
            || paymob_public_key.length === 0
            || paymob_secret_key.length === 0) {
            showAlert('Please provide Paymob API, public and secret keys');
        } else {
            jQuery.ajax({
                method: "GET",
                data: {
                    api_key: paymob_api_key,
                    pub_key: paymob_public_key,
                    sec_key: paymob_secret_key
                },
                url: url,
                dataType: 'json',
                success: function (data) {
                    if (data.success === true) {
                        jQuery('#params_hmac_secret').val(data.data.hmac);
                        var html = '';
                        var ids = '';
                        console.info(data);
                        jQuery.each(data.data.integrationIDs, function (i, integration) {
                            var text = integration.id + " : " + integration.name + " (" + integration.type + " )";
                            ids = ids + text + ',';
                            if (data.availIds !== null) {
                                // Parse the JSON string into an array
                                var availIdsArray = JSON.parse(data.availIds);
                                var selected = '';
                                if (Array.isArray(availIdsArray)) {
                                    jQuery.each(availIdsArray, function (ii, id) {
                                        if (integration.id === id || parseInt(integration.id) === parseInt(id)) {
                                            selected = 'selected';
                                        }
                                    });
                                } else if (parseInt(integration.id) === parseInt(availIdsArray)) {
                                    selected = 'selected';
                                }
                            }

                            html = html + "<option " + selected + " value=" + integration.id + ">" + text + "</option>";
                        });
                        //jQuery('#input-availIds_hidden').val(ids);
                        if (html) {
                            jQuery('#paymob-int-list').html(html);
                            jQuery('#paymob-not-valid').css('display', 'none');
                            jQuery('#paymob-valid').css('display', 'inline-block');
                        }
                    } else {
                        showAlert(data.error);
                        jQuery('#paymob-not-valid').css('display', 'inline-block');
                        jQuery('#paymob-valid').css('display', 'none');
                    }
                },

            });

        }
    }

    jQuery('.callback_copy').click(function () {
        var curr = window.location.pathname;
        var curr1 = curr.split("/");
        var url = joomlaRoot + 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived';
        var copyText = url;
        prompt("Copy link, then click OK.", copyText);
    });
});

function showAlert(message) {
    jQuery('.alert-danger').show();
    jQuery('#alertText').text(message);
    setTimeout(function () {
        jQuery('.alert-danger').fadeOut();
    }, 2000); // Adjust the time (in milliseconds) as needed
}