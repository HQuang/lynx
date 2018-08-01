<!-- BEGIN: main -->
<div class="table-responsive" style="height: {CONFIG.height}px; overflow: scroll;">
    <table class="table table-striped table-bordered table-hover">
        <thead>
            <tr>
                <th width="200">{LANG.fullname}</th>
                <th  width="150">{LANG.log_time}</th>
                <th>{LANG.content}</th>
            </tr>
        </thead>
        <tbody>
            <!-- BEGIN: loop -->
            <tr>
                <td>{LOGS.fullname}</td>
                <td>{LOGS.log_time}</td>
                <td>[{LOGS.name_key}]: {LOGS.note_action}</td>
            </tr>
            <!-- END: loop -->
        </tbody>
    </table>
</div>
<!-- END: main -->
