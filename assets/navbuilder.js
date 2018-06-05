jQuery(document).ready(function () {

    var sortableListOptions = {
        placeholderCss: {'background-color': 'cyan'}
    };

    var editor = new MenuEditor('myEditor', {listOptions: sortableListOptions, labelEdit: 'Editieren'});
    editor.setForm($('#frmEdit'));
    editor.setUpdateButton($('#btnUpdate'));
    editor.setData(navbuilderJson);

    $('#btnOut').on('click', function () {
        var str = editor.getString();
        $("#structure").text(str);

        $('#frmOut').submit();
    });

    $("#btnUpdate").click(function () {
        editor.update();
    });

    $('#btnAdd').click(function () {
        editor.add();
    });

    $('#btnAddGroup').click(function () {
        editor.addGroup();
    });

});