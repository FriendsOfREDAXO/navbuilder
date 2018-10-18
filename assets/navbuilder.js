jQuery(document).ready(function () {

    var sortableListOptions = {
        placeholderCss: {'background-color': 'cyan'}
    };

    var editor = new MenuEditor('myEditor', {listOptions: sortableListOptions, labelEdit: 'Editieren'});
    editor.setForm($('#frmEdit'));
    editor.setUpdateButton($('#btnUpdate'));
	
	if(typeof navbuilderJson !== 'undefined'){
		editor.setData(navbuilderJson);
	}

    $('#btnOut').on('click', function (e) {
        var str = editor.getString();
        $("#structure").text(str);
    });

    $('#btnAddIntern').click(function () {
        editor.addIntern();
    });

    $("#btnUpdateIntern").click(function () {
        editor.updateIntern();
    });

    $('#btnAddExtern').click(function () {
        editor.addExtern();
    });

    $("#btnUpdateExtern").click(function () {
        editor.updateExtern();
    });

    $('#btnAddGroup').click(function () {
        editor.addGroup();
    });

    $("#btnUpdateGroup").click(function () {
        editor.updateGroup();
    });

});