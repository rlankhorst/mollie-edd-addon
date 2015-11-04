# mollie-edd-addon
Mollie Payment Gateway addon for Easy Digital Downloads

This is a simple addon for the Easy Digital Downloads WordPress plugin to use the Mollie Payment Gateway. After checkout on the website it redirects the user to the payment screen of the selected payment method.

### Settings

In the Easy Digital downloads payment gateway settings, enter the Mollie API test- and production keys. You can then select the desired payment method(s) from the EDD payment gateway settings.

### To do
* Create a function to store enabled gateways and payment method icons in the database. These need to be be updated only when an admin updates the EDD settings. This way we don't need do make API calls for data that's not frequently updated.
* Create a one page checkout
