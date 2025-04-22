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

// Show/hide branch select based on radio button selection
document.addEventListener("DOMContentLoaded", () => {
  const branchTypeRadios = document.querySelectorAll('input[name="branchType"]')
  const branchSelect = document.getElementById("branchSelect")

  branchTypeRadios.forEach((radio) => {
    radio.addEventListener("change", function () {
      if (this.value === "multiple") {
        branchSelect.style.display = "block"
      } else {
        branchSelect.style.display = "none"
      }
    })
  })
})

