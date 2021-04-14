jQuery(document).ready(function ($) {
    window.onload = function() {
        var form = document.getElementsByClassName('api-form');
        var labels = form[1].getElementsByTagName("label");
        var coin_label;
        for(let i = 0; i < labels.length; i++) {
            if (labels[i].getElementsByTagName("input")[0].value == 4) {
                coin_label = labels[i];
            }
        }
        coin_label.innerHTML += '<br><br>Pay with Bitcoin, Litecoin, or other cryptocurrencies via <a href="https://alpha.coinpayments.net/" target="_blank" style="text-decoration: underline; font-weight: bold;" title="CoinPayments.net">CoinPayments.net</a></br><br>';
    }
});