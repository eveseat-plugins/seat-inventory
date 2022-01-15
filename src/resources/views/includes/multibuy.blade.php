@php
    $id = rand()
@endphp

<div class="modal fade" id="multibuyModal{{$id}}" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Mulibuy</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <textarea class="w-100" rows="15" id="multibuyTextArea{{$id}}" onclick="this.focus();this.select();document.execCommand('copy');" readonly="readonly">{{ $multibuy }}</textarea>
            </div>
            <div class="modal-footer">
                <button id="multibuyCopyButton{{$id}}" class="btn btn-primary">Copy</button>
                <button class="btn btn-secondary" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<button class="btn btn-secondary" data-toggle="modal" data-target="#multibuyModal{{$id}}">Multibuy</button>

@push('javascript')
    <script>
        $("#multibuyCopyButton{{$id}}").click(function (e) {
            const textarea = $("#multibuyTextArea{{$id}}")
            textarea.focus();
            textarea.select();
            document.execCommand('copy');
        })
    </script>
@endpush