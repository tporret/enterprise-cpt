import apiFetch from '@wordpress/api-fetch';
import {
    Button,
    Card,
    CardBody,
    Notice,
    Panel,
    PanelBody,
    SelectControl,
    Spinner,
    TextControl,
    TextareaControl,
    ToggleControl,
} from '@wordpress/components';
import '../common/admin-style.css';
import { Fragment, render, useEffect, useMemo, useState } from '@wordpress/element';
import {
    FIELD_SETTINGS_COMPONENTS,
    FIELD_TYPE_OPTIONS,
    createFieldByType,
} from './fields/registry';

const config = window.enterpriseCptFieldGroups || {
    nonce: '',
    restBase: '/enterprise-cpt/v1',
};

apiFetch.use(apiFetch.createNonceMiddleware(config.nonce || ''));

const defaultFieldGroup = () => ({
    type: 'field_group',
    name: 'new_field_group',
    title: 'New Field Group',
    post_type: '',
    locations: [{ type: 'post_type', values: ['post'] }],
    location_rules: [],
    custom_table_name: '',
    permissions: {
        minimum_role: 'any',
        custom_capability: '',
        read_only: false,
    },
    fields: [createFieldByType('text', 1)],
});

const slugify = (value) =>
    String(value || '')
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9_\-\s]/g, '')
        .replace(/[\s]+/g, '_')
        .replace(/_+/g, '_');

function FieldGroupList({ items, loading, onAddNew, onEdit, onDelete, onMoveUp, onMoveDown }) {
    return (
        <Card>
            <CardBody>
                <div className="enterprise-cpt-card-header">
                    <div>
                        <h1 className="enterprise-cpt-heading">Field Groups</h1>
                        <p className="enterprise-cpt-subheading">Manage schemas, locations, and storage mode.</p>
                    </div>
                    <Button variant="primary" className="enterprise-cpt-button--primary" onClick={onAddNew}>Add New</Button>
                </div>

                {loading ? (
                    <div className="enterprise-cpt-spinner-wrapper"><Spinner /></div>
                ) : (
                    <table className="widefat striped">
                        <thead>
                            <tr>
                                <th className="enterprise-cpt-table-cell--narrow"></th>
                                <th>Title</th>
                                <th>Name (Slug)</th>
                                <th>Locations</th>
                                <th>Storage</th>
                                <th className="enterprise-cpt-table-cell--wide">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {!items.length ? (
                                <tr><td colSpan={6}>No field groups found.</td></tr>
                            ) : null}
                            {items.map((item, index) => {
                                const isCustomTable = item.custom_table_status === 'custom_table';
                                const canMoveUp = index > 0;
                                const canMoveDown = index < items.length - 1;

                                return (
                                    <tr key={item.slug}>
                                        <td className="enterprise-cpt-table-cell enterprise-cpt-table-cell--centered">
                                            <div className="enterprise-cpt-table-actions">
                                                <Button
                                                    isSmall
                                                    variant="tertiary"
                                                    onClick={() => onMoveUp(index)}
                                                    disabled={!canMoveUp}
                                                    title="Move up"
                                                    className="enterprise-cpt-small-button"
                                                >
                                                    ↑
                                                </Button>
                                                <Button
                                                    isSmall
                                                    variant="tertiary"
                                                    onClick={() => onMoveDown(index)}
                                                    disabled={!canMoveDown}
                                                    title="Move down"
                                                    className="enterprise-cpt-small-button"
                                                >
                                                    ↓
                                                </Button>
                                            </div>
                                        </td>
                                        <td>{item.title}</td>
                                        <td><code>{item.slug}</code></td>
                                        <td>{item.locations}</td>
                                        <td>
                                            <span className={`enterprise-cpt-pill ${isCustomTable ? 'enterprise-cpt-pill--positive' : 'enterprise-cpt-pill--muted'}`}>
                                                {isCustomTable ? 'Custom Table' : 'Postmeta'}
                                            </span>
                                        </td>
                                        <td>
                                            <div className="enterprise-cpt-table-actions">
                                                <Button variant="secondary" onClick={() => onEdit(item.slug)}>Edit</Button>
                                                <Button variant="tertiary" isDestructive onClick={() => onDelete(item.slug)}>
                                                    Delete
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                )}
            </CardBody>
        </Card>
    );
}


function HeaderBar({ title, isSaving, onBack, onSave, onExport }) {
    return (
        <div className="enterprise-cpt-header enterprise-cpt-header--spaced">
            <div>
                <h1 className="enterprise-cpt-heading enterprise-cpt-heading--page">{title}</h1>
                <p className="enterprise-cpt-subheading">Edit field schema and save to JSON/DB buffer.</p>
            </div>
            <div className="enterprise-cpt-header__actions">
                <Button variant="secondary" onClick={onBack}>Back to Field Groups</Button>
                <Button variant="secondary" onClick={onExport}>Export JSON</Button>
                <Button variant="primary" onClick={onSave} isBusy={isSaving}>Save</Button>
            </div>
        </div>
    );
}

function normalizeLocationsForEditor(group) {
    if (Array.isArray(group.locations) && group.locations.length) {
        return group.locations;
    }

    const rules = Array.isArray(group.location_rules) ? group.location_rules : [];
    const derived = {};

    rules.forEach((ruleGroup) => {
        const items = Array.isArray(ruleGroup?.rules) ? ruleGroup.rules : [];
        items.forEach((rule) => {
            if (!rule || !rule.param || !rule.value) return;
            if (!derived[rule.param]) derived[rule.param] = new Set();
            derived[rule.param].add(rule.value);
        });
    });

    return Object.entries(derived).map(([type, values]) => ({
        type,
        values: Array.from(values),
    }));
}

function LocationRulesPanel({ group, onGroupChange, locationOptions, loading }) {
    const locations = normalizeLocationsForEditor(group);

    const getSelectedValues = (type) => {
        const location = locations.find((item) => item.type === type);
        return Array.isArray(location?.values) ? location.values : [];
    };

    const updateLocation = (type, values) => {
        const nextLocations = locations.filter((item) => item.type !== type);

        if (values.length) {
            nextLocations.push({ type, values });
        }

        onGroupChange({
            ...group,
            post_type: '',
            locations: nextLocations,
            location_rules: [],
        });
    };

    const renderOptionGroup = (label, type, options) => {
        const selectedValues = getSelectedValues(type);

        return (
            <div className="enterprise-cpt-option-group">
                <label className="enterprise-cpt-option-group__label">{label}</label>
                <div className="enterprise-cpt-option-grid">
                    {options.map((option) => (
                        <label
                            key={`${type}-${option.value}`}
                            className={`enterprise-cpt-location-option ${selectedValues.includes(option.value) ? 'enterprise-cpt-location-option--selected' : ''}`}
                        >
                            <input
                                type="checkbox"
                                checked={selectedValues.includes(option.value)}
                                onChange={(event) => {
                                    if (event.target.checked) {
                                        updateLocation(type, [...selectedValues, option.value]);
                                    } else {
                                        updateLocation(type, selectedValues.filter((value) => value !== option.value));
                                    }
                                }}
                            />
                            {option.label}
                        </label>
                    ))}
                </div>
            </div>
        );
    };

    if (loading) {
        return <Spinner />;
    }

    return (
        <>
            {renderOptionGroup('Post Types', 'post_type', locationOptions.post_types || [])}
            {renderOptionGroup('Taxonomies', 'taxonomy', locationOptions.taxonomies || [])}
            {renderOptionGroup('User Roles', 'user_role', locationOptions.user_roles || [])}
        </>
    );
}

function Sidebar({ group, activeFieldIndex, onGroupChange, onFieldChange, locationOptions, isLoadingLocationOptions }) {
    const fields = Array.isArray(group.fields) ? group.fields : [];
    const activeField = fields[activeFieldIndex] || null;
    const TypeSettings = activeField ? FIELD_SETTINGS_COMPONENTS[activeField.type] : null;
    const isBlock = Boolean(group.is_block);
    const permissions = group.permissions || {
        minimum_role: 'any',
        custom_capability: '',
        read_only: false,
    };

    return (
        <aside className="enterprise-cpt-sidebar">
            <Panel header="Field Group Settings">
                <PanelBody title="General" initialOpen>
                    <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                        label="Name (Slug)"
                        value={group.name || ''}
                        onChange={(value) => onGroupChange({ ...group, name: slugify(value) })}
                    />
                    <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                        label="Title"
                        value={group.title || ''}
                        onChange={(value) => onGroupChange({ ...group, title: value })}
                    />
                    <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                        label="Custom Table Name"
                        value={group.custom_table_name || ''}
                        onChange={(value) => onGroupChange({ ...group, custom_table_name: slugify(value) })}
                    />
                </PanelBody>
                {!isBlock && (
                <PanelBody title="Location Rules" initialOpen>
                    <LocationRulesPanel
                        group={group}
                        onGroupChange={onGroupChange}
                        locationOptions={locationOptions}
                        loading={isLoadingLocationOptions}
                    />
                </PanelBody>
                )}
                <PanelBody title="Gutenberg Block Settings" initialOpen={false}>
                    <ToggleControl
                        __nextHasNoMarginBottom
                        label="Register as Block"
                        help={isBlock ? 'This field group will appear as a Gutenberg block.' : 'Enable to expose this field group as a block in the editor.'}
                        checked={isBlock}
                        onChange={(checked) => onGroupChange({ ...group, is_block: checked })}
                    />
                    {isBlock && (
                        <>
                            <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                                label="Block Icon"
                                help={'Dashicon slug (e.g. "admin-post", "format-image").'}
                                value={group.block_icon || ''}
                                onChange={(value) => onGroupChange({ ...group, block_icon: value })}
                            />
                            <SelectControl
                __next40pxDefaultSize
                __nextHasNoMarginBottom
                                label="Block Category"
                                value={group.block_category || 'enterprise-cpt'}
                                options={[
                                    { value: 'enterprise-cpt', label: 'Enterprise CPT' },
                                    { value: 'text', label: 'Text' },
                                    { value: 'media', label: 'Media' },
                                    { value: 'design', label: 'Design' },
                                    { value: 'widgets', label: 'Widgets' },
                                    { value: 'embed', label: 'Embeds' },
                                ]}
                                onChange={(value) => onGroupChange({ ...group, block_category: value })}
                            />
                            <TextareaControl
                __nextHasNoMarginBottom
                                label="Description"
                                value={group.block_description || ''}
                                onChange={(value) => onGroupChange({ ...group, block_description: value })}
                            />
                        </>
                    )}
                </PanelBody>

                <PanelBody title="Security" initialOpen={false}>
                    <SelectControl
                __next40pxDefaultSize
                __nextHasNoMarginBottom
                        label="Minimum Role Required"
                        value={permissions.minimum_role || 'any'}
                        options={[
                            { value: 'any', label: 'Any' },
                            { value: 'contributor', label: 'Contributor' },
                            { value: 'author', label: 'Author' },
                            { value: 'editor', label: 'Editor' },
                            { value: 'administrator', label: 'Administrator' },
                        ]}
                        onChange={(value) => onGroupChange({
                            ...group,
                            permissions: {
                                ...permissions,
                                minimum_role: value,
                            },
                        })}
                    />
                    <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                        label="Custom Capability"
                        help='Optional capability override (e.g. "manage_financials"). If set, this takes precedence over minimum role.'
                        value={permissions.custom_capability || ''}
                        onChange={(value) => onGroupChange({
                            ...group,
                            permissions: {
                                ...permissions,
                                custom_capability: slugify(value),
                            },
                        })}
                    />
                    <ToggleControl
                        __nextHasNoMarginBottom
                        label="Read-Only Mode"
                        help="When enabled, users without edit permission can view field values but cannot edit them."
                        checked={Boolean(permissions.read_only)}
                        onChange={(checked) => onGroupChange({
                            ...group,
                            permissions: {
                                ...permissions,
                                read_only: Boolean(checked),
                            },
                        })}
                    />
                </PanelBody>

                {activeField ? (
                    <PanelBody title={`Field Settings: ${activeField.label || activeField.name || 'Field'}`} initialOpen>
                        <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                            label="Label"
                            value={activeField.label || ''}
                            onChange={(value) => onFieldChange(activeFieldIndex, { ...activeField, label: value })}
                        />
                        <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                            label="Name"
                            value={activeField.name || ''}
                            onChange={(value) => onFieldChange(activeFieldIndex, { ...activeField, name: slugify(value) })}
                        />
                        <TextControl
            __next40pxDefaultSize
            __nextHasNoMarginBottom
                            label="Help Text"
                            value={activeField.help || ''}
                            onChange={(value) => onFieldChange(activeFieldIndex, { ...activeField, help: value })}
                        />
                        {TypeSettings ? (
                            <TypeSettings
                                field={activeField}
                                onChange={(nextField) => onFieldChange(activeFieldIndex, nextField)}
                            />
                        ) : null}
                    </PanelBody>
                ) : null}
            </Panel>
        </aside>
    );
}

function MainCanvas({ group, activeFieldIndex, setActiveFieldIndex, onGroupChange }) {
    const fields = group.fields || [];
    const [newFieldType, setNewFieldType] = useState('text');

    const updateField = (index, nextField) => {
        const nextFields = [...fields];
        nextFields[index] = nextField;
        onGroupChange({ ...group, fields: nextFields });
    };

    const addField = () => {
        const nextField = createFieldByType(newFieldType, fields.length + 1);
        const nextFields = [...fields, nextField];

        onGroupChange({ ...group, fields: nextFields });
        setActiveFieldIndex(nextFields.length - 1);
    };

    const deleteField = (index, event) => {
        event.stopPropagation();
        const nextFields = fields.filter((_, i) => i !== index);
        onGroupChange({ ...group, fields: nextFields });

        if (activeFieldIndex >= nextFields.length) {
            setActiveFieldIndex(Math.max(0, nextFields.length - 1));
        } else if (activeFieldIndex === index) {
            setActiveFieldIndex(0);
        }
    };

    const moveFieldUp = (index, event) => {
        event.stopPropagation();
        if (index <= 0) {
            return;
        }

        const nextFields = [...fields];
        [nextFields[index - 1], nextFields[index]] = [nextFields[index], nextFields[index - 1]];
        onGroupChange({ ...group, fields: nextFields });
        setActiveFieldIndex(index - 1);
    };

    const moveFieldDown = (index, event) => {
        event.stopPropagation();
        if (index >= fields.length - 1) {
            return;
        }

        const nextFields = [...fields];
        [nextFields[index], nextFields[index + 1]] = [nextFields[index + 1], nextFields[index]];
        onGroupChange({ ...group, fields: nextFields });
        setActiveFieldIndex(index + 1);
    };

    return (
        <main className="enterprise-cpt-canvas">
            <div className="enterprise-cpt-canvas__header">
                <div>
                    <h2 className="enterprise-cpt-heading enterprise-cpt-heading--section">{group.title || 'Untitled Field Group'}</h2>
                    <p className="enterprise-cpt-subheading">Manage fields and ordering for this group.</p>
                </div>
                <div className="enterprise-cpt-canvas__header-actions">
                    <SelectControl
                __next40pxDefaultSize
                __nextHasNoMarginBottom
                        label="Field Type"
                        value={newFieldType}
                        options={FIELD_TYPE_OPTIONS}
                        onChange={(value) => setNewFieldType(value)}
                    />
                    <Button variant="secondary" onClick={addField}>Add Field</Button>
                </div>
            </div>

            <div className="enterprise-cpt-field-list">
                {fields.map((field, index) => (
                    <Card
                        key={`${field.name}-${index}`}
                        onClick={() => setActiveFieldIndex(index)}
                        className={`enterprise-cpt-field-card ${activeFieldIndex === index ? 'enterprise-cpt-field-card--active' : ''}`}
                    >
                        <CardBody>
                            <div className="enterprise-cpt-field-card__row">
                                <strong>{field.label || `Field ${index + 1}`}</strong>
                                <div className="enterprise-cpt-field-card__actions">
                                    <span className="enterprise-cpt-field-card__type">{field.type}</span>
                                    <Button
                                        variant="tertiary"
                                        isSmall
                                        onClick={(e) => moveFieldUp(index, e)}
                                        disabled={index <= 0}
                                        title="Move field up"
                                        className="enterprise-cpt-small-button"
                                    >
                                        ↑
                                    </Button>
                                    <Button
                                        variant="tertiary"
                                        isSmall
                                        onClick={(e) => moveFieldDown(index, e)}
                                        disabled={index >= fields.length - 1}
                                        title="Move field down"
                                        className="enterprise-cpt-small-button"
                                    >
                                        ↓
                                    </Button>
                                    <Button
                                        variant="tertiary"
                                        isDestructive
                                        isSmall
                                        onClick={(e) => deleteField(index, e)}
                                        className="enterprise-cpt-small-button enterprise-cpt-small-button--destructive"
                                    >
                                        Remove
                                    </Button>
                                </div>
                            </div>
                            <div className="enterprise-cpt-field-card__meta">
                                <code>{field.name || ''}</code>
                            </div>
                        </CardBody>
                    </Card>
                ))}
            </div>
        </main>
    );
}

function App() {
    const [currentView, setCurrentView] = useState('list');
    const [activeGroupSlug, setActiveGroupSlug] = useState(null);
    const [activeFieldIndex, setActiveFieldIndex] = useState(0);
    const [listLoading, setListLoading] = useState(true);
    const [editorLoading, setEditorLoading] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    const [listItems, setListItems] = useState([]);
    const [activeGroup, setActiveGroup] = useState(defaultFieldGroup());
    const [error, setError] = useState('');
    const [snacks, setSnacks] = useState([]);
    const [locationOptions, setLocationOptions] = useState({ post_types: [], taxonomies: [], user_roles: [] });
    const [isLoadingLocationOptions, setIsLoadingLocationOptions] = useState(true);

    const showSnack = (status, content) => {
        setSnacks((prev) => [
            ...prev,
            { id: `${Date.now()}-${Math.random()}`, status, content },
        ]);
    };

    const fetchList = async () => {
        setListLoading(true);
        setError('');

        try {
            const response = await apiFetch({ path: `${config.restBase}/field-groups` });
            setListItems(Array.isArray(response) ? response : []);
        } catch (apiError) {
            setError(apiError?.message || 'Unable to load field groups.');
        } finally {
            setListLoading(false);
        }
    };

    const fetchLocationOptions = async () => {
        setIsLoadingLocationOptions(true);

        try {
            const response = await apiFetch({ path: `${config.restBase}/location-options` });
            setLocationOptions(response || { post_types: [], taxonomies: [], user_roles: [] });
        } catch (apiError) {
            console.error('Failed to load location options:', apiError);
            setLocationOptions({ post_types: [], taxonomies: [], user_roles: [] });
        } finally {
            setIsLoadingLocationOptions(false);
        }
    };

    useEffect(() => {
        fetchList();
        fetchLocationOptions();
    }, []);

    const openNew = () => {
        setActiveGroupSlug(null);
        setActiveGroup(defaultFieldGroup());
        setActiveFieldIndex(0);
        setCurrentView('editor');
        setError('');
    };

    const openEdit = async (slug) => {
        setEditorLoading(true);
        setError('');

        try {
            const response = await apiFetch({ path: `${config.restBase}/field-groups/${slug}` });
            const nextGroup = response || defaultFieldGroup();

            setActiveGroup(nextGroup);
            setActiveGroupSlug(slug);
            setActiveFieldIndex(0);
            setCurrentView('editor');
        } catch (apiError) {
            setError(apiError?.message || 'Unable to load field group.');
            showSnack('error', apiError?.message || 'Unable to load field group.');
        } finally {
            setEditorLoading(false);
        }
    };

    const deleteItem = async (slug) => {
        if (!window.confirm(`Delete field group "${slug}"?`)) {
            return;
        }

        try {
            await apiFetch({ path: `${config.restBase}/field-groups/${slug}`, method: 'DELETE' });
            showSnack('success', `Deleted ${slug}.`);
            await fetchList();
        } catch (apiError) {
            showSnack('error', apiError?.message || 'Delete failed.');
        }
    };

    const onMoveUp = async (index) => {
        if (index <= 0) {
            return;
        }

        const newItems = [...listItems];
        [newItems[index - 1], newItems[index]] = [newItems[index], newItems[index - 1]];

        try {
            const slugs = newItems.map((item) => item.slug);
            await apiFetch({
                path: `${config.restBase}/field-groups/reorder`,
                method: 'POST',
                data: { slugs },
            });

            setListItems(newItems);
        } catch (apiError) {
            showSnack('error', apiError?.message || 'Reorder failed.');
        }
    };

    const onMoveDown = async (index) => {
        if (index >= listItems.length - 1) {
            return;
        }

        const newItems = [...listItems];
        [newItems[index], newItems[index + 1]] = [newItems[index + 1], newItems[index]];

        try {
            const slugs = newItems.map((item) => item.slug);
            await apiFetch({
                path: `${config.restBase}/field-groups/reorder`,
                method: 'POST',
                data: { slugs },
            });

            setListItems(newItems);
        } catch (apiError) {
            showSnack('error', apiError?.message || 'Reorder failed.');
        }
    };

    const saveGroup = async () => {
        const slug = slugify(activeGroup.name || '');

        if (!slug) {
            showSnack('error', 'Group slug is required.');
            return;
        }

        setIsSaving(true);
        setError('');

        try {
            const response = await apiFetch({
                path: `${config.restBase}/field-groups/save`,
                method: 'POST',
                data: {
                    slug,
                    definition: { ...activeGroup, name: slug },
                },
            });

            const saved = response?.item || null;
            const savedSlug = saved?.name || slug;

            if (saved) {
                setActiveGroup(saved);
            } else {
                setActiveGroup((prev) => ({ ...prev, name: savedSlug }));
            }

            setActiveGroupSlug(savedSlug);
            showSnack('success', `Saved ${slug}.`);

            if (response?.block_slug_warning) {
                showSnack('warning', response.block_slug_warning);
            }

            if (response?.scaffold_warning) {
                showSnack('warning', response.scaffold_warning);
            }

            await fetchList();
        } catch (apiError) {
            const message = apiError?.message || 'Save failed.';
            setError(message);
            showSnack('error', message);
        } finally {
            setIsSaving(false);
        }
    };

    const onExport = () => {
        const blob = new Blob([JSON.stringify(activeGroup, null, 2)], { type: 'application/json' });
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');

        link.href = url;
        link.download = `${activeGroup.name || 'field-group'}.json`;
        link.click();

        window.URL.revokeObjectURL(url);
    };

    const editorTitle = useMemo(() => {
        if (activeGroup.title) {
            return activeGroup.title;
        }

        return activeGroupSlug ? `Edit ${activeGroupSlug}` : 'Create Field Group';
    }, [activeGroup.title, activeGroupSlug]);

    const updateField = (index, nextField) => {
        const currentFields = Array.isArray(activeGroup.fields) ? activeGroup.fields : [];
        const nextFields = [...currentFields];
        nextFields[index] = nextField;
        setActiveGroup({ ...activeGroup, fields: nextFields });
    };

    return (
        <Fragment>
            {error ? <Notice status="error" isDismissible={false}>{error}</Notice> : null}

            {currentView === 'list' ? (
                <FieldGroupList
                    items={listItems}
                    loading={listLoading}
                    onAddNew={openNew}
                    onEdit={openEdit}
                    onDelete={deleteItem}
                    onMoveUp={onMoveUp}
                    onMoveDown={onMoveDown}
                />
            ) : null}

            {currentView === 'editor' ? (
                <Fragment>
                    {editorLoading ? (
                        <div className="enterprise-cpt-spinner-wrapper"><Spinner /></div>
                    ) : (
                        <Fragment>
                            <HeaderBar
                                title={editorTitle}
                                isSaving={isSaving}
                                onBack={() => setCurrentView('list')}
                                onSave={saveGroup}
                                onExport={onExport}
                            />
                            <div className="enterprise-cpt-shell">
                                <Sidebar
                                    group={activeGroup}
                                    activeFieldIndex={activeFieldIndex}
                                    onGroupChange={setActiveGroup}
                                    onFieldChange={updateField}
                                    locationOptions={locationOptions}
                                    isLoadingLocationOptions={isLoadingLocationOptions}
                                />
                                <MainCanvas
                                    group={activeGroup}
                                    activeFieldIndex={activeFieldIndex}
                                    setActiveFieldIndex={setActiveFieldIndex}
                                    onGroupChange={setActiveGroup}
                                />
                            </div>
                        </Fragment>
                    )}
                </Fragment>
            ) : null}

            {snacks.length ? (
                <div className="enterprise-cpt-snackbar">
                    {snacks.map((snack) => (
                        <Notice
                            key={snack.id}
                            status={snack.status}
                            isDismissible
                            onRemove={() => {
                                setSnacks((prev) => prev.filter((item) => item.id !== snack.id));
                            }}
                        >
                            {snack.content}
                        </Notice>
                    ))}
                </div>
            ) : null}
        </Fragment>
    );
}

const root = document.getElementById('enterprise-cpt-field-group-editor');

if (root) {
    render(<App />, root);
}
