<fieldset class='wa-permission-tree' data-wa-permission-tree>
    <legend>角色权限</legend>
    @foreach ($permissionTree as $groupName => $modules)
        <div class='wa-permission-group' data-wa-permission-node>
            <label class='wa-permission-parent'><input type='checkbox' data-wa-permission-parent @disabled($readOnly ?? false)><span>{{ $groupName }}</span></label>
            <div class='wa-permission-modules'>
                @foreach ($modules as $moduleName => $actions)
                    <div class='wa-permission-module' data-wa-permission-node>
                        <label class='wa-permission-parent'><input type='checkbox' data-wa-permission-parent @disabled($readOnly ?? false)><span>{{ $moduleName }}</span></label>
                        <div class='wa-permission-actions'>
                            @foreach ($actions as $key => $label)
                                <label><input type='checkbox' name='permissions[]' value='{{ $key }}' data-wa-permission-leaf @checked(in_array($key, $selectedPermissions, true)) @disabled(($readOnly ?? false) || ! $context->allows($key))><span>{{ $label }}</span></label>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</fieldset>
