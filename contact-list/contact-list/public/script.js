(function () {
    var form = document.getElementById("contact-form");
    var fields = ["first_name", "last_name", "email", "contact_number"];

    if (form) {
        form.addEventListener("submit", onSave);
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

    function onSave(event) {
        clearErrors(form);

        var errors = validateContact(form);
        if (errors.length === 0) {
            var button = form.querySelector('button[type="submit"]');
            if (button) {
                button.disabled = true;
            }
            return;
        }

        event.preventDefault();
        errors.forEach(function (error) {
            var input = form.elements[error.name];
            if (input) {
                showError(input, error.message);
            }
        });

        var first = form.elements[errors[0].name];
        if (first) {
            first.focus();
        }
    }

    function validateContact(currentForm) {
        var errors = [];
        var values = {
            first_name: readValue(currentForm, "first_name"),
            last_name: readValue(currentForm, "last_name"),
            email: readValue(currentForm, "email"),
            contact_number: readValue(currentForm, "contact_number")
        };

        checkName(errors, "first_name", "First name", values.first_name);
        checkName(errors, "last_name", "Last name", values.last_name);

        if (values.email === "") {
            errors.push({ name: "email", message: "Email is required." });
        } else if (textLength(values.email) > 50) {
            errors.push({ name: "email", message: "Email must be 50 characters or fewer." });
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email)) {
            errors.push({ name: "email", message: "Enter a valid email address." });
        } else if (emailTaken(values.email, currentId(currentForm))) {
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

    function readValue(currentForm, name) {
        var input = currentForm.elements[name];
        return input ? input.value.trim() : "";
    }

    function currentId(currentForm) {
        var input = currentForm.elements.id;
        return input ? input.value : "";
    }

    function emailTaken(email, ignoreId) {
        var needle = email.toLowerCase();
        var rows = document.querySelectorAll("tbody tr[data-email]");

        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            if (ignoreId && row.getAttribute("data-id") === ignoreId) {
                continue;
            }
            if ((row.getAttribute("data-email") || "").toLowerCase() === needle) {
                return true;
            }
        }

        return false;
    }

    function textLength(value) {
        return Array.from(value).length;
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
