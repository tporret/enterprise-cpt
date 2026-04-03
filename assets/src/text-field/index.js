import { TextControl } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { Fragment } from '@wordpress/element';
import { registerPlugin } from '@wordpress/plugins';

const config = window.enterpriseCptEditor || { textFields: [] };

function TextFieldPanel() {
    const postType = useSelect((select) => select('core/editor').getCurrentPostType(), []);
    const meta = useSelect((select) => select('core/editor').getEditedPostAttribute('meta') || {}, []);
    const { editPost } = useDispatch('core/editor');
    const fields = config.textFields.filter((field) => field.postType === postType);

    if (!fields.length) {
        return null;
    }

    return (
        <PluginDocumentSettingPanel name="enterprise-cpt-fields" title="Enterprise Fields">
            <Fragment>
                {fields.map((field) => (
                    <TextControl
                        key={field.metaKey}
                        label={field.label}
                        help={field.help}
                        value={meta[field.metaKey] || ''}
                        onChange={(value) => editPost({ meta: { ...meta, [field.metaKey]: value } })}
                    />
                ))}
            </Fragment>
        </PluginDocumentSettingPanel>
    );
}

registerPlugin('enterprise-cpt-text-fields', {
    render: TextFieldPanel,
});