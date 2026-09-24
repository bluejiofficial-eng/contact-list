(function () {
    var fields = ["first_name", "last_name", "email", "contact_number"];

    if (document.body.hasAttribute("data-standalone")) {
        startStandalone();
        return;
    }

    startServerPage();

    function startServerPage() {
        var form = document.getElementById("contact-form");
        if (form) {
            form.addEventListener("submit", function (event) {
                clearErrors(form);
                var errors = validateContact(form, null);
                if (errors.length === 0) {
                    var button = form.querySelector('button[type="submit"]');
                    if (button) {
                        button.disabled = true;
                    }
                    return;
                }
                event.preventDefault();
                showErrors(form, errors);
            });
        }

        document.querySelectorAll("form[data-confirm]").forEach(function (deleteForm) {
            deleteForm.addEventListener("submit", function (event) {
                var message = deleteForm.getAttribute("data-confirm") || "Delete this contact?";
                if (!window.confirm(message)) {
                    event.preventDefault();
                    return;
                }
                var button = deleteForm.querySelector('button[type="submit"]');
                if (button) {
                    button.disabled = true;
                }
            });
        });
    }

    function startStandalone() {
        var form = document.getElementById("contact-form");
        var state = { mode: "local", csrf: "", contacts: [] };

        form.addEventListener("submit", function (event) {
            event.preventDefault();
            clearErrors(form);
            var errors = validateContact(form, state.contacts);
            if (errors.length > 0) {
                showErrors(form, errors);
                return;
            }
            saveContact(form, state);
        });

        document.getElementById("cancel-edit").addEventListener("click", function () {
            resetForm(form);
        });

        loadContacts(state);
    }

    function loadContacts(state) {
        fetch("api.php", {
            headers: { Accept: "application/json" },
            credentials: "same-origin"
        }).then(function (response) {
            var type = response.headers.get("content-type") || "";
            if (!response.ok || type.indexOf("application/json") === -1) {
                throw new Error("offline");
            }
            return response.json();
        }).then(function (data) {
            state.mode = "api";
            state.csrf = data.csrf || "";
            state.contacts = Array.isArray(data.contacts) ? data.contacts : [];
            render(state);
        }).catch(function () {
            state.mode = "local";
            state.contacts = readLocalContacts();
            render(state);
        });
    }

    function saveContact(form, state) {
        var button = document.getElementById("save-button");
        var values = readValues(form);
        var id = currentId(form);
        button.disabled = true;

        if (state.mode === "local") {
            if (id) {
                state.contacts = state.contacts.map(function (contact) {
                    if (String(contact.id) !== id) {
                        return contact;
                    }
                    return {
                        id: contact.id,
                        first_name: values.first_name,
                        last_name: values.last_name,
                        email: values.email,
                        contact_number: values.contact_number
                    };
                });
                writeLocalContacts(state.contacts);
                showBanner("Contact updated.", "ok");
            } else {
                state.contacts.push({
                    id: nextLocalId(state.contacts),
                    first_name: values.first_name,
                    last_name: values.last_name,
                    email: values.email,
                    contact_number: values.contact_number
                });
                writeLocalContacts(state.contacts);
                showBanner("Contact added.", "ok");
            }
            resetForm(form);
            button.disabled = false;
            render(state);
            return;
        }

        var payload = {
            csrf: state.csrf,
            action: "save",
            first_name: values.first_name,
            last_name: values.last_name,
            email: values.email,
            contact_number: values.contact_number
        };
        if (id) {
            payload.id = id;
        }

        postApi(payload).then(function (result) {
            if (result.data.csrf) {
                state.csrf = result.data.csrf;
            }
            if (result.data.errors) {
                showErrors(form, errorsFromMap(result.data.errors));
                return;
            }
            if (!result.response.ok) {
                showBanner(result.data.message || "The contact list is unavailable right now.", "error");
                return;
            }
            state.contacts = result.data.contacts || [];
            showBanner(result.data.message || "Contact added.", "ok");
            resetForm(form);
            render(state);
        }).catch(function () {
            showBanner("The contact list is unavailable right now.", "error");
        }).then(function () {
            button.disabled = false;
        });
    }

    function removeContact(state, contact) {
        if (!window.confirm("Delete this contact?")) {
            return;
        }

        if (state.mode === "local") {
            state.contacts = state.contacts.filter(function (item) {
                return String(item.id) !== String(contact.id);
            });
            writeLocalContacts(state.contacts);
            if (currentId(document.getElementById("contact-form")) === String(contact.id)) {
                resetForm(document.getElementById("contact-form"));
            }
            showBanner("Contact deleted.", "ok");
            render(state);
            return;
        }

        postApi({
            csrf: state.csrf,
            action: "delete",
            id: String(contact.id)
        }).then(function (result) {
            if (result.data.csrf) {
                state.csrf = result.data.csrf;
            }
            if (!result.response.ok) {
                showBanner(result.data.message || "That contact no longer exists.", "error");
                if (Array.isArray(result.data.contacts)) {
                    state.contacts = result.data.contacts;
                    render(state);
                }
                return;
            }
            state.contacts = result.data.contacts || [];
            if (currentId(document.getElementById("contact-form")) === String(contact.id)) {
                resetForm(document.getElementById("contact-form"));
            }
            showBanner(result.data.message || "Contact deleted.", "ok");
            render(state);
        }).catch(function () {
            showBanner("The contact list is unavailable right now.", "error");
        });
    }

    function postApi(payload) {
        return fetch("api.php", {
            method: "POST",
            credentials: "same-origin",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json"
            },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().then(function (data) {
                return { response: response, data: data };
            });
        });
    }

    function render(state) {
        var contacts = state.contacts.slice().sort(compareContacts);
        var count = document.getElementById("count-label");
        var empty = document.getElementById("empty-state");
        var table = document.getElementById("table-wrap");
        var rows = document.getElementById("contact-rows");
        var note = document.getElementById("storage-note");

        count.textContent = contacts.length === 1 ? "1 person" : contacts.length + " people";
        empty.hidden = contacts.length !== 0;
        table.hidden = contacts.length === 0;
        note.hidden = state.mode !== "local";
        rows.replaceChildren();

        contacts.forEach(function (contact) {
            var tr = document.createElement("tr");
            tr.dataset.id = String(contact.id);
            tr.dataset.email = contact.email;
            appendCell(tr, "Last name", contact.last_name);
            appendCell(tr, "First name", contact.first_name);
            appendCell(tr, "Email", contact.email);
            appendCell(tr, "Contact number", contact.contact_number);

            var actions = document.createElement("td");
            actions.className = "actions";

            var edit = document.createElement("button");
            edit.type = "button";
            edit.className = "text-link";
            edit.textContent = "Edit";
            edit.addEventListener("click", function () {
                startEdit(contact);
            });

            var remove = document.createElement("button");
            remove.type = "button";
            remove.textContent = "Delete";
            remove.addEventListener("click", function () {
                removeContact(state, contact);
            });

            actions.append(edit, remove);
            tr.append(actions);
            rows.append(tr);
        });
    }

    function startEdit(contact) {
        var form = document.getElementById("contact-form");
        form.elements.id.value = String(contact.id);
        form.elements.first_name.value = contact.first_name;
        form.elements.last_name.value = contact.last_name;
        form.elements.email.value = contact.email;
        form.elements.contact_number.value = contact.contact_number;
        document.getElementById("form-title").textContent = "Edit contact";
        document.getElementById("save-button").textContent = "Save changes";
        document.getElementById("cancel-edit").hidden = false;
        clearErrors(form);
        form.elements.first_name.focus();
    }

    function resetForm(form) {
        form.reset();
        form.elements.id.value = "";
        document.getElementById("form-title").textContent = "Add contact";
        document.getElementById("save-button").textContent = "Add contact";
        document.getElementById("cancel-edit").hidden = true;
        clearErrors(form);
    }

    function showBanner(message, type) {
        var banner = document.getElementById("banner");
        banner.hidden = false;
        banner.textContent = message;
        banner.className = "banner banner-" + (type === "error" ? "error" : "ok");
        banner.setAttribute("role", type === "error" ? "alert" : "status");
    }

    function appendCell(row, label, value) {
        var cell = document.createElement("td");
        cell.dataset.label = label;
        cell.textContent = value;
        row.append(cell);
    }

    function compareContacts(a, b) {
        var last = a.last_name.localeCompare(b.last_name, undefined, { sensitivity: "base" });
        if (last !== 0) {
            return last;
        }
        var first = a.first_name.localeCompare(b.first_name, undefined, { sensitivity: "base" });
        if (first !== 0) {
            return first;
        }
        return Number(a.id) - Number(b.id);
    }

    function readLocalContacts() {
        try {
            var parsed = JSON.parse(window.localStorage.getItem("contact-list.contacts") || "[]");
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    }

    function writeLocalContacts(contacts) {
        window.localStorage.setItem("contact-list.contacts", JSON.stringify(contacts));
    }

    function nextLocalId(contacts) {
        return contacts.reduce(function (max, contact) {
            return Math.max(max, Number(contact.id) || 0);
        }, 0) + 1;
    }

    function errorsFromMap(map) {
        return fields.filter(function (name) {
            return map[name];
        }).map(function (name) {
            return { name: name, message: map[name] };
        });
    }

    function validateContact(currentForm, contacts) {
        var errors = [];
        var values = readValues(currentForm);

        checkName(errors, "first_name", "First name", values.first_name);
        checkName(errors, "last_name", "Last name", values.last_name);

        if (values.email === "") {
            errors.push({ name: "email", message: "Email is required." });
        } else if (textLength(values.email) > 50) {
            errors.push({ name: "email", message: "Email must be 50 characters or fewer." });
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email)) {
            errors.push({ name: "email", message: "Enter a valid email address." });
        } else if (emailTaken(values.email, currentId(currentForm), contacts)) {
            errors.push({ name: "email", message: "A contact with this email already exists." });
        }

        if (values.contact_number === "") {
            errors.push({ name: "contact_number", message: "Contact number is required." });
        } else if (!/^\d{1,15}$/.test(values.contact_number)) {
            errors.push({
                name: "contact_number",
                message: "Contact number must contain only digits and be at most 15 digits."
            });
        }

        return errors;
    }

    function checkName(errors, name, label, value) {
        if (value === "") {
            errors.push({ name: name, message: label + " is required." });
        } else if (textLength(value) > 50) {
            errors.push({ name: name, message: label + " must be 50 characters or fewer." });
        }
    }

    function readValues(currentForm) {
        return {
            first_name: readValue(currentForm, "first_name"),
            last_name: readValue(currentForm, "last_name"),
            email: readValue(currentForm, "email"),
            contact_number: readValue(currentForm, "contact_number")
        };
    }

    function readValue(currentForm, name) {
        var input = currentForm.elements[name];
        return input ? input.value.trim() : "";
    }

    function currentId(currentForm) {
        var input = currentForm.elements.id;
        return input ? String(input.value || "") : "";
    }

    function emailTaken(email, ignoreId, contacts) {
        var needle = email.toLowerCase();
        var list = contacts || Array.prototype.map.call(
            document.querySelectorAll("tbody tr[data-email]"),
            function (row) {
                return { id: row.getAttribute("data-id"), email: row.getAttribute("data-email") || "" };
            }
        );

        return list.some(function (contact) {
            if (ignoreId && String(contact.id) === String(ignoreId)) {
                return false;
            }
            return String(contact.email || "").toLowerCase() === needle;
        });
    }

    function textLength(value) {
        return Array.from(value).length;
    }

    function showErrors(currentForm, errors) {
        errors.forEach(function (error) {
            var input = currentForm.elements[error.name];
            if (input) {
                showError(input, error.message);
            }
        });
        var first = currentForm.elements[errors[0].name];
        if (first) {
            first.focus();
        }
    }

    function clearErrors(currentForm) {
        currentForm.querySelectorAll(".error").forEach(function (node) {
            node.remove();
        });
        fields.forEach(function (name) {
            var input = currentForm.elements[name];
            if (!input) {
                return;
            }
            input.removeAttribute("aria-invalid");
            syncDescribedBy(input);
        });
    }

    function showError(input, message) {
        var field = input.closest(".field");
        if (!field) {
            return;
        }

        var error = document.createElement("p");
        error.className = "error";
        error.id = input.id + "-error";
        error.textContent = message;
        field.appendChild(error);
        input.setAttribute("aria-invalid", "true");
        syncDescribedBy(input);
    }

    function syncDescribedBy(input) {
        var ids = [];
        var hint = document.getElementById(input.id + "-hint");
        var error = document.getElementById(input.id + "-error");

        if (hint) {
            ids.push(hint.id);
        }
        if (error) {
            ids.push(error.id);
        }

        if (ids.length > 0) {
            input.setAttribute("aria-describedby", ids.join(" "));
        } else {
            input.removeAttribute("aria-describedby");
        }
    }
})();
