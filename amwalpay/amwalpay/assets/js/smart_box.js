document.addEventListener("DOMContentLoaded", function () {
    if (window.SmartBoxData) {
        callSmartBox(window.SmartBoxData);
        function callSmartBox(data) {
            if (data["MID"] === "" || data["TID"] === "") {
                document.getElementById("Error").style.display = "block";
                return;
            }
            var base_url = window.BaseUrl;
            var callback = window.CallBack;
            console.log(data);
            SmartBox.Checkout.configure = {
                ...data,

                completeCallback: function (data) {

                    var dateResponse = data.data.data;
                    window.location = callback + '&amount=' + dateResponse.amount + '&currencyId=' + dateResponse.currencyId + '&customerId=' + dateResponse.customerId + '&customerTokenId=' + dateResponse.customerTokenId + '&merchantReference=' + dateResponse.merchantReference + '&responseCode=' + data.data.responseCode + '&transactionId=' + dateResponse.transactionId + '&transactionTime=' + dateResponse.transactionTime + '&secureHashValue=' + dateResponse.secureHashValue;
                },
                errorCallback: function (data) {

                },
                cancelCallback: function () {
                    window.location = base_url;
                },
            };

            SmartBox.Checkout.showSmartBox()
        }
    }
});
