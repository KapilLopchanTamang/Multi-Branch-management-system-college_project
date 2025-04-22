// Toggle password visibility
function togglePassword() {
    const passwordInput = document.getElementById("password")
    const toggleIcon = document.querySelector(".toggle-password i")
  
    if (passwordInput.type === "password") {
      passwordInput.type = "text"
      toggleIcon.classList.remove("fa-eye")
      toggleIcon.classList.add("fa-eye-slash")
    } else {
      passwordInput.type = "password"
      toggleIcon.classList.remove("fa-eye-slash")
      toggleIcon.classList.add("fa-eye")
    }
  }
  
  // Toggle password visibility for specific field
  function togglePasswordVisibility(fieldId) {
    const passwordInput = document.getElementById(fieldId)
    const toggleIcon = document.querySelector(`#${fieldId} + .toggle-password i`)
  
    if (passwordInput.type === "password") {
      passwordInput.type = "text"
      toggleIcon.classList.remove("fa-eye")
      toggleIcon.classList.add("fa-eye-slash")
    } else {
      passwordInput.type = "password"
      toggleIcon.classList.remove("fa-eye-slash")
      toggleIcon.classList.add("fa-eye")
    }
  }
  
  