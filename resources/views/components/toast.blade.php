@php
    $success = session('success');
    $error = session('error');
    $type = $success ? 'success' : ($error ? 'error' : null);
    $message = $success ?: ($error ?: null);
@endphp

@if($type && $message)
    {{-- A server flash and a fetch answer are the same event, so this renders no
         dialog of its own: it hands the message to the layout's single dialog
         kernel, which builds the same sheet every other result gets. --}}
    <script>
        window.pendingFlashDialog = @json(['type' => $type, 'message' => $message]);
    </script>
@endif
