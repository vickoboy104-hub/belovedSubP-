{{-- One intention, one charge. This identity is spent the first time the form is
     accepted, so a double-click, a browser retry or a Back-then-Submit cannot buy
     the same request twice; a deliberate second purchase gets a fresh one when the
     page is shown again or the result of the first is answered. --}}
<input type="hidden" name="intent_token" data-purchase-intent value="{{ \App\Services\PurchaseIntent::mint() }}">
